<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class AudioFileChunkerService
{
    /**
     * @return array{session_id: string, directory: string, source_path: string, duration_ms: int}
     */
    public function createSession(UploadedFile $file): array
    {
        $sessionId = (string) Str::uuid();
        $workDirectory = $this->sessionDirectory($sessionId);
        File::ensureDirectoryExists($workDirectory);

        $sourcePath = $workDirectory.DIRECTORY_SEPARATOR.'source.'.$this->extension($file);
        $file->move($workDirectory, basename($sourcePath));

        $durationMs = max(1, (int) round($this->probeDurationSeconds($sourcePath) * 1000));

        file_put_contents($workDirectory.DIRECTORY_SEPARATOR.'session.json', json_encode([
            'source_path' => $sourcePath,
            'duration_ms' => $durationMs,
            'created_at' => now()->toISOString(),
        ]));

        return [
            'session_id' => $sessionId,
            'directory' => $workDirectory,
            'source_path' => $sourcePath,
            'duration_ms' => $durationMs,
        ];
    }

    /**
     * @return array{path: string, name: string, mime_type: string, size: int, duration_ms: int}
     */
    public function extractSegment(string $sessionId, int $clipIndex, int $startMs, int $durationMs): array
    {
        $session = $this->readSession($sessionId);
        $directory = $this->sessionDirectory($sessionId);
        $outputPath = $directory.DIRECTORY_SEPARATOR.sprintf('chunk_%05d.wav', $clipIndex);

        $this->runProcess([
            $this->ffmpegPath(),
            '-y',
            '-ss',
            sprintf('%.3f', $startMs / 1000),
            '-t',
            sprintf('%.3f', max(1, $durationMs) / 1000),
            '-i',
            $session['source_path'],
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            $outputPath,
        ]);

        if (! is_file($outputPath)) {
            throw new RuntimeException('Audio section could not be prepared.');
        }

        $preparedDurationMs = max(1, (int) round($this->probeDurationSeconds($outputPath) * 1000));

        return [
            'path' => $outputPath,
            'name' => basename($outputPath),
            'mime_type' => 'audio/wav',
            'size' => filesize($outputPath) ?: 0,
            'duration_ms' => $preparedDurationMs,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function buildSections(int $durationMs, int $chunkSeconds): array
    {
        $chunkMs = max(1, $chunkSeconds) * 1000;
        $count = max(1, (int) ceil($durationMs / $chunkMs));

        return array_map(function (int $index) use ($chunkMs, $durationMs): array {
            $startMs = $index * $chunkMs;
            $endMs = min(($index + 1) * $chunkMs, $durationMs);

            return [
                'index' => $index + 1,
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'duration_ms' => max(1, $endMs - $startMs),
                'range_label' => $this->formatRange($startMs, $endMs),
            ];
        }, range(0, $count - 1));
    }

    /**
     * @return array{directory: string, segments: array<int, array<string, mixed>>}
     */
    public function split(UploadedFile $file, int $chunkSeconds = 60): array
    {
        $chunkSeconds = max(1, $chunkSeconds);
        $workDirectory = storage_path('app/private/audio-upload-chunks/'.uniqid('upload-', true));
        File::ensureDirectoryExists($workDirectory);

        $sourcePath = $workDirectory.DIRECTORY_SEPARATOR.'source.'.$this->extension($file);
        $file->move($workDirectory, basename($sourcePath));

        $outputPattern = $workDirectory.DIRECTORY_SEPARATOR.'chunk_%05d.wav';

        $this->runProcess([
            $this->ffmpegPath(),
            '-y',
            '-i',
            $sourcePath,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-c:a',
            'pcm_s16le',
            '-f',
            'segment',
            '-segment_time',
            (string) $chunkSeconds,
            '-reset_timestamps',
            '1',
            $outputPattern,
        ]);

        $files = collect(File::files($workDirectory))
            ->filter(fn ($path) => str_starts_with($path->getFilename(), 'chunk_') && $path->getExtension() === 'wav')
            ->sortBy(fn ($path) => $path->getFilename())
            ->values();

        if ($files->isEmpty()) {
            throw new RuntimeException('Audio could not be prepared for transcription.');
        }

        $segments = [];
        $cursorMs = 0;

        foreach ($files as $index => $chunkFile) {
            $durationMs = max(1, (int) round($this->probeDurationSeconds($chunkFile->getPathname()) * 1000));
            $startMs = $cursorMs;
            $endMs = $startMs + $durationMs;

            $segments[] = [
                'index' => $index + 1,
                'path' => $chunkFile->getPathname(),
                'name' => $chunkFile->getFilename(),
                'mime_type' => 'audio/wav',
                'size' => $chunkFile->getSize(),
                'start_ms' => $startMs,
                'end_ms' => $endMs,
                'duration_ms' => $durationMs,
                'range_label' => $this->formatRange($startMs, $endMs),
            ];

            $cursorMs = $endMs;
        }

        return [
            'directory' => $workDirectory,
            'segments' => $segments,
        ];
    }

    public function cleanup(string $directory): void
    {
        if (str_starts_with($directory, storage_path('app/private/audio-upload-chunks')) && File::isDirectory($directory)) {
            File::deleteDirectory($directory);
        }
    }

    private function extension(UploadedFile $file): string
    {
        return $file->getClientOriginalExtension() ?: 'audio';
    }

    /**
     * @return array{source_path: string, duration_ms: int, created_at?: string}
     */
    private function readSession(string $sessionId): array
    {
        $path = $this->sessionDirectory($sessionId).DIRECTORY_SEPARATOR.'session.json';

        if (! is_file($path)) {
            throw new RuntimeException('Upload session was not found.');
        }

        $session = json_decode((string) file_get_contents($path), true);

        if (! is_array($session) || empty($session['source_path']) || ! is_file($session['source_path'])) {
            throw new RuntimeException('Upload session source audio was not found.');
        }

        return $session;
    }

    private function sessionDirectory(string $sessionId): string
    {
        return storage_path('app/private/audio-upload-sessions/'.$sessionId);
    }

    private function probeDurationSeconds(string $path): float
    {
        $process = $this->runProcess([
            $this->ffprobePath(),
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);

        return (float) trim($process->getOutput());
    }

    private function runProcess(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout(null);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(trim($process->getErrorOutput()) ?: 'Audio processing failed.');
        }

        return $process;
    }

    private function ffmpegPath(): string
    {
        return base_path('ffmpeg/bin/ffmpeg.exe');
    }

    private function ffprobePath(): string
    {
        return base_path('ffmpeg/bin/ffprobe.exe');
    }

    private function formatRange(int $startMs, int $endMs): string
    {
        return $this->formatClock($startMs).'-'.$this->formatClock($endMs);
    }

    private function formatClock(int $milliseconds): string
    {
        $totalSeconds = max(0, intdiv($milliseconds, 1000));
        $hours = intdiv($totalSeconds, 3600);
        $minutes = intdiv($totalSeconds % 3600, 60);
        $seconds = $totalSeconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);
        }

        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}
