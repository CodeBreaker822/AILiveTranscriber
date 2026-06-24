<?php

namespace App\Services;

class GeminiTranscriptCleanerService
{
    public function __construct(
        private readonly HostedTranscriptionApiService $api,
    ) {
    }

    /**
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, model: string}
     */
    public function clean(string $text, array $timestamps = [], array $options = []): array
    {
        return $this->api->polish($text, $timestamps, $options);
    }

    /**
     * @param  array<int, array{id: int, range_label?: string|null, text: string, timestamps: array<int, array<string, mixed>>}>  $chunks
     * @return array{chunks: array<int, array{audio_chunk_id: int, text: string, timestamps: array<int, array<string, mixed>>}>, model: string}
     */
    public function cleanChunks(array $chunks, array $options = []): array
    {
        return $this->api->polishChunks($chunks, $options);
    }
}
