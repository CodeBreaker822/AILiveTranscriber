<?php

namespace App\Services;

use App\Exceptions\TranscriptPolisherException;
use App\Exceptions\SpeechToTextException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SplFileInfo;

class HostedTranscriptionApiService
{
    public function __construct(
        private readonly AppSettingsService $settings,
    ) {
    }

    public function licenseStatus(?string $licenseKey = null): array
    {
        try {
            $response = $this->request($licenseKey)->get($this->url('/license/status'));
        } catch (ConnectionException $exception) {
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        }

        if ($response->failed()) {
            throw new SpeechToTextException($this->messageForResponse($response, 'License status could not be checked.'));
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    public function serverIsReachable(): bool
    {
        try {
            Http::acceptJson()
                ->connectTimeout(3)
                ->timeout(5)
                ->get($this->settings->apiBaseUrl());

            // Any HTTP response means the hosted server is reachable. This quiet
            // probe intentionally sends no license key and inspects no content.
            return true;
        } catch (ConnectionException) {
            return false;
        }
    }

    public function downloadUpdateArchive(): Response
    {
        try {
            return $this->request()
                ->timeout(600)
                ->withOptions(['stream' => true])
                ->get($this->url('/transcribe/update/zipfile'));
        } catch (ConnectionException $exception) {
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('update server'), 0, $exception);
        }
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider?: string, model?: string}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $file = $this->resolveAudioFile($audio);
        $selection = $this->settings->transcriptionSelection($options['language_code'] ?? null);

        if ($selection['provider'] === '' || $selection['model'] === '') {
            throw new SpeechToTextException('Save and test your license key in Settings before transcribing audio.');
        }

        $contents = file_get_contents($file['path']);

        if ($contents === false) {
            throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        try {
            $response = $this->request()
                ->attach('audio', $contents, $file['name'])
                ->post($this->url('/transcribe'), [
                    'provider' => $selection['provider'],
                    'model' => $selection['model'],
                    'language_code' => $selection['language'],
                    'clip_index' => $options['clip_index'] ?? null,
                    'clip_start_ms' => $options['clip_start_ms'] ?? null,
                    'clip_end_ms' => $options['clip_end_ms'] ?? null,
                ]);
        } catch (ConnectionException $exception) {
            throw new SpeechToTextException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        }

        if ($response->failed()) {
            Log::error('Hosted transcription request failed.', [
                'status' => $response->status(),
                'provider' => $selection['provider'],
                'model' => $selection['model'],
                'language_code' => $selection['language'],
                'file_name' => $file['name'],
                'response' => $response->json() ?? $response->body(),
            ]);

            throw new SpeechToTextException($this->messageForResponse($response, ServiceUserMessage::transcriptionFailed('Transcription server')), $response->status());
        }

        $payload = $response->json() ?? [];

        return [
            'text' => (string) ($payload['text'] ?? ''),
            'timestamps' => is_array($payload['timestamps'] ?? null) ? array_values(array_filter($payload['timestamps'], 'is_array')) : [],
            'provider' => $payload['provider'] ?? $selection['provider'],
            'model' => $payload['model'] ?? $selection['model'],
        ];
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider: string|null, model: string|null}
     */
    public function polish(string $text, array $timestamps = [], array $options = []): array
    {
        $text = trim($text);
        $polisher = $this->polisherSelection();

        if ($text === '') {
            return [
                'text' => '',
                'timestamps' => [],
                'provider' => $polisher['provider'],
                'model' => $polisher['model'],
            ];
        }

        $response = $this->postJson('/polish', [
            'text' => $text,
            'timestamps' => $timestamps,
            'instruction' => trim((string) ($options['instructions'] ?? '')),
        ]);

        return [
            'text' => (string) ($response['text'] ?? ''),
            'timestamps' => is_array($response['timestamps'] ?? null) ? array_values(array_filter($response['timestamps'], 'is_array')) : [],
            'provider' => $this->nullableString($response['provider'] ?? $polisher['provider']),
            'model' => $this->nullableString($response['model'] ?? $polisher['model']),
        ];
    }

    /**
     * @param  array<int, array{id: int, range_label?: string|null, text: string, timestamps: array<int, array<string, mixed>>}>  $chunks
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, provider: string|null, model: string|null}
     */
    public function polishChunks(array $chunks, array $options = []): array
    {
        $polisher = $this->polisherSelection();
        $payloadChunks = array_values(array_map(
            fn (array $chunk): array => [
                'audio_chunk_id' => (int) $chunk['id'],
                'clip_index' => $chunk['clip_index'] ?? null,
                'range_label' => $chunk['range_label'] ?? null,
                'text' => trim((string) ($chunk['text'] ?? '')),
                'timestamps' => array_values(array_filter($chunk['timestamps'] ?? [], 'is_array')),
            ],
            $chunks,
        ));

        if ($payloadChunks === [] || collect($payloadChunks)->every(fn (array $chunk): bool => $chunk['text'] === '')) {
            return [
                'chunks' => array_map(
                    fn (array $chunk): array => [
                        'audio_chunk_id' => (int) $chunk['audio_chunk_id'],
                        'text' => '',
                        'timestamps' => [],
                    ],
                    $payloadChunks,
                ),
                'provider' => $polisher['provider'],
                'model' => $polisher['model'],
            ];
        }

        $response = $this->postJson('/polish', [
            'chunks' => $payloadChunks,
            'instruction' => trim((string) ($options['instructions'] ?? '')),
        ]);

        return [
            'chunks' => array_values(array_map(
                fn (array $chunk): array => [
                    'audio_chunk_id' => (int) ($chunk['audio_chunk_id'] ?? 0),
                    'text' => (string) ($chunk['text'] ?? ''),
                    'timestamps' => is_array($chunk['timestamps'] ?? null) ? array_values(array_filter($chunk['timestamps'], 'is_array')) : [],
                ],
                array_filter($response['chunks'] ?? [], 'is_array'),
            )),
            'provider' => $this->nullableString($response['provider'] ?? $polisher['provider']),
            'model' => $this->nullableString($response['model'] ?? $polisher['model']),
        ];
    }

    private function postJson(string $path, array $payload): array
    {
        try {
            $response = $this->request()
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($this->url($path), $payload);
        } catch (SpeechToTextException $exception) {
            throw new TranscriptPolisherException($exception->getMessage(), 0, $exception);
        } catch (ConnectionException $exception) {
            throw new TranscriptPolisherException(ServiceUserMessage::cannotReachProvider('transcription server'), 0, $exception);
        }

        if ($response->failed()) {
            throw new TranscriptPolisherException($this->messageForResponse($response, ServiceUserMessage::cleanerFailed()), $response->status());
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function request(?string $licenseKey = null)
    {
        $licenseKey = trim((string) ($licenseKey ?? $this->settings->licenseKey()));

        if ($licenseKey === '') {
            throw new SpeechToTextException('Add your license key in Settings before continuing.');
        }

        return Http::withToken($licenseKey)
            ->acceptJson()
            ->timeout((int) config('services.transcription_api.timeout', 120));
    }

    private function url(string $path): string
    {
        return $this->settings->apiBaseUrl().'/'.ltrim($path, '/');
    }

    private function messageForResponse(Response $response, string $fallback): string
    {
        $message = $response->json('message');

        if (is_string($message) && trim($message) !== '') {
            return $message;
        }

        if ($response->status() === 429) {
            $retryAfter = (int) ($response->json('retry_after') ?? 0);

            return $retryAfter > 0
                ? "License key is rate-limited. Try again in {$retryAfter} seconds."
                : 'License key is rate-limited. Please wait and try again.';
        }

        return $fallback;
    }

    /**
     * @return array{path: string, name: string}
     */
    private function resolveAudioFile(UploadedFile|string|SplFileInfo $audio): array
    {
        if ($audio instanceof UploadedFile) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getClientOriginalName() ?: $audio->getFilename(),
            ];
        }

        if ($audio instanceof SplFileInfo) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getFilename(),
            ];
        }

        if (! is_file($audio)) {
            throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $audio,
            'name' => basename($audio),
        ];
    }

    /**
     * @return array{provider: string|null, model: string|null}
     */
    private function polisherSelection(): array
    {
        $providers = $this->settings->licenseStatus()['providers']['polishing'] ?? [];

        if (! is_array($providers)) {
            return [
                'provider' => null,
                'model' => null,
            ];
        }

        foreach ($providers as $provider) {
            if (! is_array($provider)) {
                continue;
            }

            if (! ($provider['configured'] ?? false) || ! ($provider['enabled'] ?? false) || ! ($provider['connected'] ?? false)) {
                continue;
            }

            $models = is_array($provider['models'] ?? null) ? $provider['models'] : [];

            return [
                'provider' => $this->nullableString($provider['provider'] ?? null),
                'model' => $this->nullableString($models[0]['id'] ?? null),
            ];
        }

        return [
            'provider' => null,
            'model' => null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
