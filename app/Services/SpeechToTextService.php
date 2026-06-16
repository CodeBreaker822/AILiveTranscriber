<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use SplFileInfo;

class SpeechToTextService
{
    public function __construct(
        private readonly AppSettingsService $settings,
        private readonly ElevenLabsSpeechToTextService $elevenLabs,
        private readonly DeepgramSpeechToTextService $deepgram,
    ) {
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        return match ($this->settings->speechToTextProvider()) {
            'deepgram' => $this->deepgram->transcribe($audio, $options),
            default => $this->elevenLabs->transcribe($audio, $options),
        };
    }
}
