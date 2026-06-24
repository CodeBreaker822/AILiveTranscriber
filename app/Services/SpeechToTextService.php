<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use SplFileInfo;

class SpeechToTextService
{
    public function __construct(
        private readonly HostedTranscriptionApiService $api,
    ) {
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        return $this->api->transcribe($audio, $options);
    }
}
