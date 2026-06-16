<?php

namespace App\Services;

use App\Exceptions\ElevenLabsSpeechToTextException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use SplFileInfo;

class ElevenLabsSpeechToTextService
{
    public const MODEL_SCRIBE_V2 = 'scribe_v2';

    public const MODEL_SCRIBE_V1 = 'scribe_v1';

    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $endpoint = null,
        private readonly ?string $modelId = null,
        private readonly ?int $timeout = null,
    ) {
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $file = $this->resolveAudioFile($audio);
        $stream = fopen($file['path'], 'rb');

        if ($stream === false) {
            throw new ElevenLabsSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        try {
            $response = $this->client()
                ->attach('file', $stream, $file['name'])
                ->post($this->getEndpoint(), $this->payload($options));
        } catch (ConnectionException $exception) {
            throw new ElevenLabsSpeechToTextException(
                ServiceUserMessage::cannotReachProvider('ElevenLabs'),
                0,
                $exception,
            );
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        if ($response->failed()) {
            throw new ElevenLabsSpeechToTextException(
                $this->userMessageForFailedResponse($response->status()),
                $response->status()
            );
        }

        return $this->normalizeTranscript($response->json() ?? []);
    }

    /**
     * @return array{path: string, name: string}
     */
    private function resolveAudioFile(UploadedFile|string|SplFileInfo $audio): array
    {
        if ($audio instanceof UploadedFile) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new ElevenLabsSpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getClientOriginalName() ?: $audio->getFilename(),
            ];
        }

        if ($audio instanceof SplFileInfo) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new ElevenLabsSpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getFilename(),
            ];
        }

        if (! is_file($audio)) {
            throw new ElevenLabsSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $audio,
            'name' => basename($audio),
        ];
    }

    private function client(): PendingRequest
    {
        $apiKey = $this->apiKey
            ?? app(AppSettingsService::class)->elevenLabsApiKey()
            ?? config('services.elevenlabs.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new ElevenLabsSpeechToTextException(ServiceUserMessage::missingApiKey('ElevenLabs'));
        }

        return Http::withHeaders([
            'xi-api-key' => trim($apiKey),
        ])->timeout($this->timeout ?? (int) config('services.elevenlabs.timeout', 120));
    }

    private function getEndpoint(): string
    {
        return $this->endpoint ?? (string) config('services.elevenlabs.speech_to_text_url');
    }

    private function payload(array $options): array
    {
        $payload = [
            'model_id' => $this->resolveModelId($options['model_id'] ?? null),
            'diarize' => $options['diarize'] ?? true,
            'tag_audio_events' => $options['tag_audio_events'] ?? true,
            'timestamps_granularity' => $options['timestamps_granularity'] ?? 'word',
        ];

        $languageCode = trim((string) ($options['language_code'] ?? ''));

        if ($languageCode !== '') {
            $payload['language_code'] = $languageCode;
        }

        return $payload;
    }

    private function resolveModelId(?string $modelId): string
    {
        $modelId = $modelId
            ?? $this->modelId
            ?? config('services.elevenlabs.speech_to_text_model', self::MODEL_SCRIBE_V2);

        $allowedModels = config('services.elevenlabs.speech_to_text_models', [
            self::MODEL_SCRIBE_V2,
            self::MODEL_SCRIBE_V1,
        ]);

        if (! is_string($modelId) || ! in_array($modelId, $allowedModels, true)) {
            throw new ElevenLabsSpeechToTextException(ServiceUserMessage::unsupportedProviderModel('ElevenLabs'));
        }

        return $modelId;
    }

    private function userMessageForFailedResponse(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('ElevenLabs'),
            $status === 429 => ServiceUserMessage::providerBusy('ElevenLabs'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('ElevenLabs'),
            default => ServiceUserMessage::transcriptionFailed('ElevenLabs'),
        };
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    private function normalizeTranscript(array $response): array
    {
        $words = is_array($response['words'] ?? null) ? $response['words'] : [];

        return [
            'text' => (string) ($response['text'] ?? ''),
            'timestamps' => array_values(array_map(
                fn (array $word): array => [
                    'text' => (string) ($word['text'] ?? ''),
                    'start' => $word['start'] ?? null,
                    'end' => $word['end'] ?? null,
                    'type' => $word['type'] ?? null,
                    'speaker_id' => $word['speaker_id'] ?? null,
                ],
                array_filter($words, 'is_array'),
            )),
        ];
    }
}
