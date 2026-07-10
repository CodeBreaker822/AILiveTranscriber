<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use SplFileInfo;

class SpeechToTextService
{
    public function __construct(
        private readonly HostedTranscriptionApiService $api,
        private readonly OfflineWhisperService $offlineWhisper,
    ) {
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        if (($options['engine'] ?? 'online') === 'offline') {
            return $this->offlineWhisper->transcribe($audio, $options);
        }

        return $this->api->transcribe($audio, $options);
    }

    /**
     * @param  array<int, array{audio: UploadedFile|string|SplFileInfo, clip_index?: int, clip_start_ms?: int, clip_end_ms?: int}>  $clips
     * @return array<int, array{text: string, timestamps: array<int, array<string, mixed>>, clip_index?: int, clip_start_ms?: int, clip_end_ms?: int, provider?: string|null, model?: string|null}>
     */
    public function transcribeBatch(array $clips, array $options = []): array
    {
        if (($options['engine'] ?? 'online') !== 'offline') {
            return $this->api->transcribeBatch($clips, $options);
        }

        $clips = array_values($clips);
        $lastIndex = array_key_last($clips);

        return array_map(function (array $clip, int $index) use ($options, $lastIndex): array {
            $clipOptions = [
                ...$options,
                'clip_index' => $clip['clip_index'] ?? null,
                'clip_start_ms' => $clip['clip_start_ms'] ?? null,
                'clip_end_ms' => $clip['clip_end_ms'] ?? null,
                'release_worker' => (bool) ($options['release_worker'] ?? false) && $index === $lastIndex,
            ];
            $transcription = $this->offlineWhisper->transcribe($clip['audio'], $clipOptions);

            return [
                ...$transcription,
                'clip_index' => $clip['clip_index'] ?? null,
                'clip_start_ms' => $clip['clip_start_ms'] ?? null,
                'clip_end_ms' => $clip['clip_end_ms'] ?? null,
            ];
        }, array_values($clips), array_keys(array_values($clips)));
    }

    public function releaseOfflineWorker(array $options = []): void
    {
        if (($options['engine'] ?? 'online') === 'offline') {
            $this->offlineWhisper->releaseWorker();
        }
    }
}
