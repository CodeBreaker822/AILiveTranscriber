<?php

namespace App\Services;

use App\Exceptions\SpeechToTextException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

class OfflineWhisperService
{
    public function __construct(
        private readonly OfflineWhisperModelService $models,
    ) {
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider: string, model: string}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $audioPath = $this->audioPath($audio);
        $binaryPath = $this->binaryPath();
        $model = trim((string) ($options['model'] ?? OfflineWhisperModelService::DEFAULT_MODEL));
        $modelPath = $this->modelPath($model);
        $memoryBudgetMb = max(0, (int) config('services.whisper.memory_budget_mb', 0));
        $requiredMemoryMb = $this->models->requiredMemoryMb($model);

        if ($memoryBudgetMb > 0 && $requiredMemoryMb > $memoryBudgetMb) {
            throw new SpeechToTextException(
                "The selected Whisper model needs about {$requiredMemoryMb} MB of working memory, "
                ."but AITranscriber reserved only {$memoryBudgetMb} MB to keep this computer responsive. Choose a smaller model."
            );
        }

        $threadBudget = max(1, (int) config('services.whisper.threads', 2));
        $outputDirectory = storage_path('app/private');
        File::ensureDirectoryExists($outputDirectory);
        $outputPath = tempnam($outputDirectory, 'offline-whisper-');

        if ($outputPath === false) {
            throw new SpeechToTextException('Offline transcription output could not be prepared.');
        }

        $language = trim((string) ($options['language_code'] ?? 'auto'));
        $process = new Process(array_values(array_filter([
            $binaryPath,
            '--offline-whisper',
            '--model',
            $modelPath,
            '--audio',
            $audioPath,
            '--language',
            $language !== '' ? $language : 'auto',
            '--threads',
            (string) $threadBudget,
            '--output',
            $outputPath,
        ], fn ($value): bool => $value !== null)));
        $process->setTimeout((int) config('services.whisper.timeout', 1800));

        try {
            $process->run();
            $payload = json_decode((string) @file_get_contents($outputPath), true);
        } catch (Throwable $exception) {
            throw new SpeechToTextException('Offline Whisper transcription did not finish.', 0, $exception);
        } finally {
            @unlink($outputPath);
        }

        if (! $process->isSuccessful() || ! is_array($payload)) {
            $message = is_array($payload) && is_string($payload['error'] ?? null)
                ? trim($payload['error'])
                : trim($process->getErrorOutput());

            throw new SpeechToTextException($message !== '' ? $message : 'Offline Whisper transcription failed.');
        }

        $text = trim((string) ($payload['text'] ?? ''));

        if ($text === '') {
            throw new SpeechToTextException('Offline Whisper detected speech but returned no transcript. Please retry or choose another model.');
        }

        return [
            'text' => $text,
            'timestamps' => is_array($payload['timestamps'] ?? null)
                ? array_values(array_filter($payload['timestamps'], 'is_array'))
                : [],
            'provider' => (string) ($payload['provider'] ?? 'whisper.cpp'),
            'model' => (string) ($payload['model'] ?? 'large-v3-turbo-q8_0'),
        ];
    }

    public function isAvailable(): bool
    {
        return $this->findBinaryPath() !== null && $this->models->hasSupportedInstalledModel();
    }

    public function modelIsAvailable(): bool
    {
        return $this->models->hasSupportedInstalledModel();
    }

    private function audioPath(UploadedFile|string|SplFileInfo $audio): string
    {
        $path = match (true) {
            $audio instanceof UploadedFile => $audio->getRealPath(),
            $audio instanceof SplFileInfo => $audio->getRealPath(),
            default => $audio,
        };

        if (! is_string($path) || ! is_file($path)) {
            throw new SpeechToTextException(ServiceUserMessage::audioReadFailed());
        }

        return $path;
    }

    private function binaryPath(): string
    {
        return $this->findBinaryPath()
            ?? throw new SpeechToTextException('Offline Whisper is available in the Tauri desktop app only.');
    }

    private function modelPath(string $model): string
    {
        return $this->models->activeModelPath($model)
            ?? throw new SpeechToTextException('The selected offline Whisper model is not installed.');
    }

    private function findBinaryPath(): ?string
    {
        $configured = trim((string) config('services.whisper.binary', ''));
        $binaryName = PHP_OS_FAMILY === 'Windows' ? 'aitranscriber.exe' : 'aitranscriber';
        $candidates = array_values(array_filter([
            $configured !== '' ? $configured : null,
            base_path('src-tauri/target/release/'.$binaryName),
            base_path('src-tauri/target/debug/'.$binaryName),
        ]));

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

}
