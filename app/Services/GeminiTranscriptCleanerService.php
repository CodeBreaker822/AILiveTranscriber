<?php

namespace App\Services;

use App\Exceptions\GeminiTranscriptCleanerException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GeminiTranscriptCleanerService
{
    public function __construct(
        private readonly ?string $apiKey = null,
        private readonly ?string $model = null,
        private readonly ?string $endpointTemplate = null,
        private readonly ?int $timeout = null,
    ) {
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, model: string}
     */
    public function clean(string $text, array $timestamps = [], array $options = []): array
    {
        $text = trim($text);

        if ($text === '') {
            return [
                'text' => '',
                'timestamps' => [],
                'model' => $this->getModel(),
            ];
        }

        $response = $this->postGenerateContent($this->payload($text, $timestamps, $options));

        $content = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($content) || trim($content) === '') {
            throw new GeminiTranscriptCleanerException(ServiceUserMessage::emptyCleanerResponse());
        }

        return $this->normalizeCleanedTranscript($content, $timestamps);
    }

    /**
     * @param  array<int, array{id: int, range_label?: string|null, text: string, timestamps: array<int, array<string, mixed>>}>  $chunks
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, model: string}
     */
    public function cleanChunks(array $chunks, array $options = []): array
    {
        $chunks = array_values(array_map(
            fn (array $chunk): array => [
                'id' => (int) $chunk['id'],
                'range_label' => $chunk['range_label'] ?? null,
                'text' => trim((string) ($chunk['text'] ?? '')),
                'timestamps' => array_values(array_filter($chunk['timestamps'] ?? [], 'is_array')),
            ],
            $chunks,
        ));

        if ($chunks === [] || collect($chunks)->every(fn (array $chunk): bool => $chunk['text'] === '')) {
            return [
                'chunks' => array_map(
                    fn (array $chunk): array => [
                        'audio_chunk_id' => $chunk['id'],
                        'text' => '',
                        'timestamps' => [],
                    ],
                    $chunks,
                ),
                'model' => $this->getModel(),
            ];
        }

        $response = $this->postGenerateContent($this->chunkPayload($chunks, $options));

        $content = $response->json('candidates.0.content.parts.0.text');

        if (! is_string($content) || trim($content) === '') {
            throw new GeminiTranscriptCleanerException(ServiceUserMessage::emptyCleanerResponse());
        }

        return $this->normalizeCleanedChunks($content, $chunks);
    }

    private function client(): PendingRequest
    {
        return Http::withHeaders([
            'Content-Type' => 'application/json',
        ])->timeout($this->timeout ?? app(AppSettingsService::class)->geminiTimeout());
    }

    private function endpoint(): string
    {
        $apiKey = $this->getApiKey();
        $url = $this->endpointTemplate
            ? sprintf($this->endpointTemplate, $this->getModel())
            : sprintf('%s/models/%s:generateContent', rtrim((string) config('services.gemini.base_url'), '/'), $this->getModel());

        return $url . (str_contains($url, '?') ? '&' : '?') . 'key=' . urlencode($apiKey);
    }

    private function getModel(): string
    {
        return $this->model ?? app(AppSettingsService::class)->geminiModel();
    }

    private function getApiKey(): string
    {
        $apiKey = $this->apiKey
            ?? app(AppSettingsService::class)->geminiApiKey()
            ?? config('services.gemini.key');

        if (! is_string($apiKey) || trim($apiKey) === '') {
            throw new GeminiTranscriptCleanerException(ServiceUserMessage::missingApiKey('Gemini'));
        }

        return trim($apiKey);
    }

    private function postGenerateContent(array $payload): Response
    {
        $this->checkGlobalRateLimit();

        $startedAt = microtime(true);
        $logContext = $this->geminiLogContext($payload);

        Log::info('Gemini transcript cleaner request started.', $logContext);

        try {
            $response = $this->client()
                ->retry(
                    app(AppSettingsService::class)->geminiMaxRetries(),
                    1000,
                    fn ($exception): bool => $exception instanceof ConnectionException
                        || (($exception->response ?? null) && (
                            $exception->response->status() >= 500
                            || $exception->response->status() === 429
                        )),
                    throw: false,
                )
                ->post($this->endpoint(), $payload);
        } catch (ConnectionException $exception) {
            Log::error('Gemini transcript cleaner request timed out.', array_merge($logContext, [
                'elapsed_ms' => $this->elapsedMs($startedAt),
                'error' => $exception->getMessage(),
            ]));

            throw new GeminiTranscriptCleanerException(
                ServiceUserMessage::cannotReachProvider('Gemini'),
                0,
                $exception,
            );
        }

        if ($response->failed()) {
            Log::error('Gemini transcript cleaner request failed.', array_merge($logContext, [
                'elapsed_ms' => $this->elapsedMs($startedAt),
                'status' => $response->status(),
                'response_body' => $response->json() ?? $response->body(),
            ]));

            if ($response->status() === 429) {
                Cache::put($this->rateLimitKey(), (int) config('services.gemini.rpm_limit', 15), 60);
            }

            throw new GeminiTranscriptCleanerException(
                $this->userMessageForFailedResponse($response->status()),
                $response->status(),
            );
        }

        Log::info('Gemini transcript cleaner response received.', array_merge($logContext, [
            'elapsed_ms' => $this->elapsedMs($startedAt),
            'status' => $response->status(),
            'ai_reply' => $response->json('candidates.0.content.parts.0.text'),
        ]));

        return $response;
    }

    private function checkGlobalRateLimit(): void
    {
        $limit = max(1, (int) config('services.gemini.rpm_limit', 15));
        $key = $this->rateLimitKey();
        $timeKey = $key . '_time';
        $startedAt = (int) Cache::get($timeKey, 0);
        $now = time();

        if ($startedAt === 0 || ($now - $startedAt) >= 60) {
            Cache::put($key, 0, 60);
            Cache::put($timeKey, $now, 60);
        }

        $requests = (int) Cache::get($key, 0);

        if ($requests >= $limit) {
            throw new GeminiTranscriptCleanerException(ServiceUserMessage::providerBusy('Gemini'));
        }

        Cache::put($key, $requests + 1, max(1, 60 - ($now - (int) Cache::get($timeKey, $now))));
    }

    private function rateLimitKey(): string
    {
        return (string) config('services.gemini.rate_limit_key', 'gemini_global_requests_per_minute');
    }

    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function geminiLogContext(array $payload): array
    {
        $userText = (string) ($payload['contents'][0]['parts'][0]['text'] ?? '');
        $decoded = json_decode($userText, true);
        $chunks = [];

        if (is_array($decoded['chunks'] ?? null)) {
            $chunks = array_map(
                fn (array $chunk): array => [
                    'audio_chunk_id' => $chunk['audio_chunk_id'] ?? null,
                    'range_label' => $chunk['range_label'] ?? null,
                    'raw_text' => $chunk['raw_text'] ?? '',
                    'timestamp_count' => is_array($chunk['timestamps'] ?? null) ? count($chunk['timestamps']) : 0,
                ],
                $decoded['chunks'],
            );
        }

        if (isset($decoded['raw_text'])) {
            $chunks[] = [
                'audio_chunk_id' => null,
                'range_label' => null,
                'raw_text' => $decoded['raw_text'],
                'timestamp_count' => is_array($decoded['timestamps'] ?? null) ? count($decoded['timestamps']) : 0,
            ];
        }

        return [
            'model' => $this->getModel(),
            'endpoint' => $this->redactedEndpoint(),
            'timeout_seconds' => $this->timeout ?? app(AppSettingsService::class)->geminiTimeout(),
            'max_retries' => app(AppSettingsService::class)->geminiMaxRetries(),
            'system_instruction' => $payload['system_instruction']['parts'][0]['text'] ?? null,
            'chunks' => $chunks,
            'request_character_count' => strlen($userText),
        ];
    }

    private function redactedEndpoint(): string
    {
        return preg_replace('/([?&]key=)[^&]+/', '$1[redacted]', $this->endpoint()) ?? '[unavailable]';
    }

    private function payload(string $text, array $timestamps, array $options): array
    {
        return [
            'system_instruction' => [
                'parts' => [
                    [
                        'text' => implode(' ', array_merge(
                            $this->cleanupInstructions('export'),
                            ['Return JSON only with keys text and timestamps.'],
                        )),
                    ],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => json_encode([
                                'raw_text' => $text,
                                'timestamps' => $timestamps,
                                'instructions' => $options['instructions'] ?? null,
                                'timestamp_schema' => [
                                    'text' => 'word or cleaned segment text',
                                    'start' => 'original start time when available',
                                    'end' => 'original end time when available',
                                    'type' => 'original token type when available',
                                    'speaker_id' => 'original speaker id when available',
                                ],
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.2,
            ],
        ];
    }

    private function chunkPayload(array $chunks, array $options): array
    {
        return [
            'system_instruction' => [
                'parts' => [
                    [
                        'text' => implode(' ', array_merge(
                            $this->cleanupInstructions('client export'),
                            [
                                'Clean each chunk independently and keep the same audio_chunk_id values.',
                                'Do not merge chunks, split chunks, or change timestamps except removing words that were removed from the transcript.',
                                'Return JSON only with one key: chunks.',
                            ],
                        )),
                    ],
                ],
            ],
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => json_encode([
                                'chunks' => array_map(
                                    fn (array $chunk): array => [
                                        'audio_chunk_id' => $chunk['id'],
                                        'range_label' => $chunk['range_label'],
                                        'raw_text' => $chunk['text'],
                                        'timestamps' => $chunk['timestamps'],
                                    ],
                                    $chunks,
                                ),
                                'instructions' => $options['instructions'] ?? null,
                                'response_schema' => [
                                    'chunks' => [
                                        [
                                            'audio_chunk_id' => 'same id from request',
                                            'text' => 'cleaned transcript text for this chunk only',
                                            'timestamps' => [
                                                [
                                                    'text' => 'word or cleaned segment text',
                                                    'start' => 'original start time when available',
                                                    'end' => 'original end time when available',
                                                    'type' => 'original token type when available',
                                                    'speaker_id' => 'original speaker id when available',
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        ],
                    ],
                ],
            ],
            'generationConfig' => [
                'temperature' => $options['temperature'] ?? 0.2,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    private function cleanupInstructions(string $purpose): array
    {
        return [
            "You clean speech-to-text transcripts for {$purpose}.",
            'The speaker may code-switch between English, Cebuano/Bisaya, Filipino/Tagalog, and government-office terms.',
            'Repair obvious speech-to-text spelling errors, broken words, missing spaces, and phonetic mistakes in Cebuano/Bisaya/Filipino when the intended word is clear from context.',
            'Preserve the original language mix; do not translate Cebuano/Bisaya/Filipino words into English.',
            'Keep names, titles, agencies, places, and official terms readable with proper capitalization, such as DILG, Sangguniang, board member, council, and province names.',
            'Remove filler words such as uh, um, uhm, ah, I mean, and repeated false starts when removing them does not change the meaning.',
            'Fix grammar, punctuation, casing, and sentence boundaries without changing meaning.',
            'If a broken local-language word is uncertain, keep it close to the source instead of inventing a new meaning.',
            'Preserve the timestamp structure needed for export.',
        ];
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, model: string}
     */
    private function normalizeCleanedTranscript(string $content, array $fallbackTimestamps): array
    {
        $decoded = json_decode($this->stripJsonFence($content), true);

        if (! is_array($decoded)) {
            throw new GeminiTranscriptCleanerException(ServiceUserMessage::invalidCleanerResponse());
        }

        $timestamps = is_array($decoded['timestamps'] ?? null)
            ? array_values(array_filter($decoded['timestamps'], 'is_array'))
            : $fallbackTimestamps;

        return [
            'text' => (string) ($decoded['text'] ?? ''),
            'timestamps' => array_map(
                fn (array $item): array => [
                    'text' => (string) ($item['text'] ?? ''),
                    'start' => $item['start'] ?? null,
                    'end' => $item['end'] ?? null,
                    'type' => $item['type'] ?? null,
                    'speaker_id' => $item['speaker_id'] ?? null,
                ],
                $timestamps,
            ),
            'model' => $this->getModel(),
        ];
    }

    /**
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, model: string}
     */
    private function normalizeCleanedChunks(string $content, array $sourceChunks): array
    {
        $decoded = json_decode($this->stripJsonFence($content), true);

        if (! is_array($decoded) || ! is_array($decoded['chunks'] ?? null)) {
            throw new GeminiTranscriptCleanerException(ServiceUserMessage::invalidCleanerResponse());
        }

        $sourceIds = array_flip(array_map(fn (array $chunk): int => (int) $chunk['id'], $sourceChunks));
        $cleaned = [];

        foreach ($decoded['chunks'] as $chunk) {
            if (! is_array($chunk)) {
                continue;
            }

            $audioChunkId = (int) ($chunk['audio_chunk_id'] ?? 0);

            if (! isset($sourceIds[$audioChunkId])) {
                continue;
            }

            $timestamps = is_array($chunk['timestamps'] ?? null)
                ? array_values(array_filter($chunk['timestamps'], 'is_array'))
                : [];

            $cleaned[$audioChunkId] = [
                'audio_chunk_id' => $audioChunkId,
                'text' => (string) ($chunk['text'] ?? $chunk['raw_text'] ?? ''),
                'timestamps' => array_map(
                    fn (array $item): array => [
                        'text' => (string) ($item['text'] ?? ''),
                        'start' => $item['start'] ?? null,
                        'end' => $item['end'] ?? null,
                        'type' => $item['type'] ?? null,
                        'speaker_id' => $item['speaker_id'] ?? null,
                    ],
                    $timestamps,
                ),
            ];
        }

        foreach ($sourceChunks as $sourceChunk) {
            if (! isset($cleaned[(int) $sourceChunk['id']])) {
                throw new GeminiTranscriptCleanerException(ServiceUserMessage::cleanerMissingChunks());
            }
        }

        return [
            'chunks' => array_values($cleaned),
            'model' => $this->getModel(),
        ];
    }

    private function stripJsonFence(string $content): string
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```(?:json)?\s*/i', '', $content) ?? $content;
            $content = preg_replace('/\s*```$/', '', $content) ?? $content;
        }

        return trim($content);
    }

    private function userMessageForFailedResponse(int $status): string
    {
        return match (true) {
            in_array($status, [401, 403], true) => ServiceUserMessage::providerRejectedKey('Gemini'),
            $status === 429 => ServiceUserMessage::providerBusy('Gemini'),
            $status >= 500 => ServiceUserMessage::providerUnavailable('Gemini'),
            default => ServiceUserMessage::cleanerFailed(),
        };
    }
}
