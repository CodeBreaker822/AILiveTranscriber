<?php

namespace App\Services\Speech;

use App\Exceptions\SpeechToTextException;

class OfflineWhisperBinaryLocator
{
    public function binaryPath(): string
    {
        return $this->findBinaryPath()
            ?? throw new SpeechToTextException('Offline Whisper is available in the Tauri desktop app only.');
    }

    public function findBinaryPath(): ?string
    {
        $configured = trim((string) config('services.whisper.binary', ''));
        $editionBinaryName = config('app.edition') === 'jerva'
            ? 'jerva-transcriber.exe'
            : 'astra-transcriber.exe';
        $runtimeExecutable = trim((string) env('AI_TRANSCRIBER_EXECUTABLE', ''));
        $candidates = array_values(array_filter([
            $runtimeExecutable !== '' ? $runtimeExecutable : null,
            $configured !== '' ? $configured : null,
            base_path('src-tauri/target/release/'.$editionBinaryName),
            base_path('src-tauri/target/debug/'.$editionBinaryName),
            base_path('src-tauri/target/release/aitranscriber.exe'),
            base_path('src-tauri/target/debug/aitranscriber.exe'),
        ]));

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
