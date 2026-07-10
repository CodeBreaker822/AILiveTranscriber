<?php

namespace App\Services;

use App\Exceptions\SpeechToTextException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use SplFileInfo;
use Symfony\Component\Process\Process;
use Throwable;

class OfflineWhisperService
{
    private const MAX_WORKER_RETRIES = 3;

    public function __construct(
        private readonly OfflineWhisperModelService $models,
        private readonly AppSettingsService $settings,
    ) {
    }

    /**
     * @param  UploadedFile|string|SplFileInfo  $audio
     * @return array{text: string, timestamps: array<int, array<string, mixed>>, provider: string, model: string}
     */
    public function transcribe(UploadedFile|string|SplFileInfo $audio, array $options = []): array
    {
        $audioPath = $this->audioPath($audio);
        $model = trim((string) ($options['model'] ?? OfflineWhisperModelService::DEFAULT_MODEL));
        $modelPath = $this->modelPath($model);
        $resourceProfile = $this->settings->resourceProfile();
        $memoryBudgetMb = (int) $resourceProfile['memory_budget_mb'];
        $requiredMemoryMb = $this->models->requiredMemoryMb($model);

        if ($memoryBudgetMb > 0 && $requiredMemoryMb > $memoryBudgetMb) {
            throw new SpeechToTextException(
                "The selected Whisper model needs about {$requiredMemoryMb} MB of working memory, "
                ."but AITranscriber reserved only {$memoryBudgetMb} MB to keep this computer responsive. Choose a smaller model."
            );
        }

        $threadBudget = max(1, (int) $resourceProfile['cpu_threads']);
        $gpuVramBudgetMb = max(0, (int) $resourceProfile['gpu_vram_budget_mb']);
        $useGpu = ($resourceProfile['gpu_available'] ?? false) === true
            && $gpuVramBudgetMb >= $this->models->requiredGpuMemoryMb($model);
        $language = trim((string) ($options['language_code'] ?? 'auto'));
        $workerPayload = $this->workerRequest([
            'action' => 'transcribe',
            'model_path' => $modelPath,
            'audio_path' => $audioPath,
            'language' => $language !== '' ? $language : 'auto',
            'threads' => $threadBudget,
            'use_gpu' => $useGpu,
            'gpu_vram_budget_mb' => $gpuVramBudgetMb,
            'progress_id' => trim((string) ($options['progress_id'] ?? '')) ?: null,
            'release' => (bool) ($options['release_worker'] ?? false),
        ]);

        if ($workerPayload !== null) {
            return $this->normalizePayload($workerPayload);
        }

        $binaryPath = $this->binaryPath();
        $outputDirectory = storage_path('app/private');
        File::ensureDirectoryExists($outputDirectory);
        $outputPath = tempnam($outputDirectory, 'offline-whisper-');

        if ($outputPath === false) {
            throw new SpeechToTextException('Offline transcription output could not be prepared.');
        }

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
            $useGpu ? '--gpu' : '--cpu',
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

        return $this->normalizePayload($payload);
    }

    public function releaseWorker(): void
    {
        $this->workerRequest(['action' => 'release']);
    }

    private function normalizePayload(array $payload): array
    {
        if (is_string($payload['error'] ?? null) && trim($payload['error']) !== '') {
            throw new SpeechToTextException(trim($payload['error']));
        }

        $text = trim((string) ($payload['text'] ?? ''));

        return [
            'text' => $text,
            'timestamps' => is_array($payload['timestamps'] ?? null)
                ? array_values(array_filter($payload['timestamps'], 'is_array'))
                : [],
            'provider' => (string) ($payload['provider'] ?? 'whisper.cpp'),
            'model' => (string) ($payload['model'] ?? 'large-v3-turbo-q8_0'),
        ];
    }

    /**
     * @return array<string, mixed>|null Null means no live Tauri worker is
     * available and the one-shot executable fallback should be used.
     */
    private function workerRequest(array $request): ?array
    {
        $endpointPath = storage_path('app/private/offline-whisper-worker.json');

        if (! is_file($endpointPath)) {
            return null;
        }

        $endpoint = json_decode((string) @file_get_contents($endpointPath), true);
        $address = is_array($endpoint) ? trim((string) ($endpoint['address'] ?? '')) : '';
        $token = is_array($endpoint) ? trim((string) ($endpoint['token'] ?? '')) : '';

        if ($address === '' || $token === '') {
            return null;
        }

        $timeout = max(1, (int) config('services.whisper.timeout', 1800));
        $encoded = json_encode(['token' => $token, ...$request], JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new SpeechToTextException('Offline Whisper worker request could not be sent.');
        }

        $attempts = ($request['action'] ?? null) === 'transcribe' ? self::MAX_WORKER_RETRIES + 1 : 1;
        $lastFailure = 'the worker closed its connection without returning JSON';

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $errorCode = 0;
            $errorMessage = '';
            $socket = @stream_socket_client(
                'tcp://'.$address,
                $errorCode,
                $errorMessage,
                2,
                STREAM_CLIENT_CONNECT,
            );

            if (! is_resource($socket)) {
                // The endpoint is stale because the persistent worker exited.
                // Returning null activates the isolated executable fallback.
                return null;
            }

            stream_set_timeout($socket, $timeout);

            if (fwrite($socket, $encoded."\n") === false) {
                fclose($socket);
                $lastFailure = 'the request could not be written to the worker socket';
                $this->logWorkerRetry($attempt, $attempts, $lastFailure, '');
                usleep(100_000);

                continue;
            }

            $response = stream_get_contents($socket);
            $metadata = stream_get_meta_data($socket);
            fclose($socket);

            if (($metadata['timed_out'] ?? false) === true) {
                throw new SpeechToTextException('Offline Whisper transcription timed out.');
            }

            $payload = json_decode((string) $response, true);

            if (is_array($payload)) {
                if (($payload['retryable'] ?? false) === true && $attempt < $attempts) {
                    $lastFailure = trim((string) ($payload['error'] ?? '')) ?: 'the native worker requested a retry';
                    $this->logWorkerRetry($attempt, $attempts, $lastFailure, (string) $response);
                    usleep(150_000);

                    continue;
                }

                return $payload;
            }

            $lastFailure = trim((string) $response) === ''
                ? 'the worker closed its connection without returning JSON'
                : 'the worker returned malformed JSON: '.json_last_error_msg();
            $this->logWorkerRetry($attempt, $attempts, $lastFailure, (string) $response);

            if ($attempt < $attempts) {
                usleep(150_000);
            }
        }

        throw new SpeechToTextException(
            "Offline Whisper failed after {$attempts} attempts because {$lastFailure}."
        );
    }

    private function logWorkerRetry(int $attempt, int $attempts, string $failure, string $response): void
    {
        Log::warning('Offline Whisper worker exchange failed.', [
            'attempt' => $attempt,
            'max_attempts' => $attempts,
            'failure' => $failure,
            'response_bytes' => strlen($response),
            'response_prefix_hex' => bin2hex(substr($response, 0, 96)),
        ]);
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
        $binaryName = 'aitranscriber.exe';
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
