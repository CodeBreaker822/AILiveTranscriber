<?php

namespace App\Services;

use App\Exceptions\DeepgramSpeechToTextException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use SplFileInfo;

class DeepgramSpeechToTextService
{
    public const MODEL_NOVA_3 = 'nova-3';

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
        $contents = file_get_contents($file['path']);

        if ($contents === false) {
            throw new DeepgramSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        try {
            $response = $this->client()
                ->withBody($contents, $file['mime_type'])
                ->post($this->endpointWithQuery($options));
        } catch (ConnectionException $exception) {
            throw new DeepgramSpeechToTextException(
                ServiceUserMessage::cannotReachProvider('Deepgram'),
                0,
                $exception,
            );
        }

        if ($response->failed()) {
            throw new DeepgramSpeechToTextException(
                $this->userMessageForFailedResponse($response->status()),
                $response->status()
            );
        }

        return $this->normalizeTranscript($response->json() ?? []);
    }

    /**
     * @return array{path: string, name: string, mime_type: string}
     */
    private function resolveAudioFile(UploadedFile|string|SplFileInfo $audio): array
    {
        if ($audio instanceof UploadedFile) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new DeepgramSpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getClientOriginalName() ?: $audio->getFilename(),
                'mime_type' => $audio->getMimeType() ?: 'application/octet-stream',
            ];
        }

        if ($audio instanceof SplFileInfo) {
            $path = $audio->getRealPath();

            if (! is_string($path) || ! is_file($path)) {
                throw new DeepgramSpeechToTextException(ServiceUserMessage::audioReadFailed());
            }

            return [
                'path' => $path,
                'name' => $audio->getFilename(),
                'mime_type' => $this->mimeType($path),
            ];
        }

        if (! is_file($audio)) {
            throw new DeepgramSpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return [
            'path' => $audio,
            'name' => basename($audio),
            'mime_type' => $this->mimeType($audio),
        ];
    }

    private function client(): PendingRequest
    {
        $apiKey = $this->apiKey
            ?? app(AppSettingsService::class)->deepgramApiKey()
            ?? config('services.deepgram.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new DeepgramSpeechToTextException(ServiceUserMessage::missingApiKey('Deepgram'));
        }

        return Http::withHeaders([
            'Authorization' => 'Token '.trim($apiKey),
        ])->timeout($this->timeout ?? (int) config('services.deepgram.timeout', 120));
    }

    private function endpointWithQuery(array $options): string
    {
        $query = [
            'model' => $this->resolveModelId(),
            'punctuate' => 'true',
            'smart_format' => 'true',
            'diarize' => 'true',
        ];

        $languageCode = $this->normalizeLanguageCode($options['language_code'] ?? null);

        if ($languageCode !== null) {
            $query['language'] = $languageCode;
        }

        return $this->getEndpoint().'?'.http_build_query($query);
    }

    private function getEndpoint(): string
    {
        return $this->endpoint ?? (string) config('services.deepgram.listen_url');
    }

    private function resolveModelId(): string
    {
        $modelId = $this->modelId
            ?? app(AppSettingsService::class)->deepgramModel()
            ?? config('services.deepgram.model', self::MODEL_NOVA_3);

        if ($modelId === 'nova3') {
            $modelId = self::MODEL_NOVA_3;
        }

        $allowedModels = config('services.deepgram.speech_to_text_models', [
            self::MODEL_NOVA_3,
            'nova-2',
        ]);

        if (! is_string($modelId) || ! in_array($modelId, $allowedModels, true)) {
            throw new DeepgramSpeechToTextException(ServiceUserMessage::unsupportedProviderModel('Deepgram'));
        }

        return $modelId;
    }

    private function userMessageForFailedResponse(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('Deepgram'),
            $status === 429 => ServiceUserMessage::providerBusy('Deepgram'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('Deepgram'),
            default => ServiceUserMessage::transcriptionFailed('Deepgram'),
        };
    }

    private function normalizeLanguageCode(?string $languageCode): ?string
    {
        $languageCode = trim((string) $languageCode);

        if ($languageCode === '') {
            return null;
        }

        return match ($languageCode) {
            'eng' => 'en',
            'zho' => 'zh',
            default => $languageCode,
        };
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    private function normalizeTranscript(array $response): array
    {
        $alternative = $response['results']['channels'][0]['alternatives'][0] ?? [];
        $alternative = is_array($alternative) ? $alternative : [];
        $words = is_array($alternative['words'] ?? null) ? $alternative['words'] : [];

        return [
            'text' => (string) ($alternative['transcript'] ?? ''),
            'timestamps' => array_values(array_map(
                fn (array $word): array => [
                    'text' => (string) ($word['punctuated_word'] ?? $word['word'] ?? ''),
                    'start' => $word['start'] ?? null,
                    'end' => $word['end'] ?? null,
                    'type' => 'word',
                    'speaker_id' => isset($word['speaker']) ? 'speaker_'.$word['speaker'] : null,
                ],
                array_filter($words, 'is_array'),
            )),
        ];
    }

    private function mimeType(string $path): string
    {
        $mimeType = function_exists('mime_content_type') ? mime_content_type($path) : false;

        return is_string($mimeType) && $mimeType !== '' ? $mimeType : 'application/octet-stream';
    }
}
