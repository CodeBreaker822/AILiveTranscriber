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
}
