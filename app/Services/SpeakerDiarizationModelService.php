<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use PharData;
use RuntimeException;
use Throwable;

class SpeakerDiarizationModelService
{
    public const MODEL_ID = 'diarization';
    public const SEGMENTATION_FILE = 'pyannote-segmentation-3.0-int8.onnx';
    public const EMBEDDING_FILE = 'nemo-en-titanet-small.onnx';

    private const SEGMENTATION_ARCHIVE_FILE = 'sherpa-onnx-pyannote-segmentation-3-0.tar.bz2';
    private const SEGMENTATION_ARCHIVE_ENTRY = 'sherpa-onnx-pyannote-segmentation-3-0/model.int8.onnx';
    private const SEGMENTATION_ARCHIVE_BYTES = 6_958_444;
    private const SEGMENTATION_ARCHIVE_SHA256 = '24615ee884c897d9d2ba09bb4d30da6bb1b15e685065962db5b02e76e4996488';
    private const SEGMENTATION_BYTES = 1_540_506;
    private const SEGMENTATION_SHA256 = 'd582f4b4c6b48205de7e0643c57df0df5615a3c176189be3fc461e9d18827b5d';
    private const EMBEDDING_BYTES = 40_257_283;
    private const EMBEDDING_SHA256 = 'ad4a1802485d8b34c722d2a9d04249662f2ece5d28a7a039063ca22f515a789e';

    public function __construct(private readonly TrustedHttpClient $http)
    {
    }

    /** @return array{segmentation: string, embedding: string}|null */
    public function activeModelPaths(): ?array
    {
        $segmentation = $this->segmentationPath();
        $embedding = $this->embeddingPath();

        if (! $this->validFile($segmentation, self::SEGMENTATION_BYTES, self::SEGMENTATION_SHA256)
            || ! $this->validFile($embedding, self::EMBEDDING_BYTES, self::EMBEDDING_SHA256)) {
            return null;
        }

        return compact('segmentation', 'embedding');
    }

    public function isInstalled(): bool
    {
        return $this->activeModelPaths() !== null;
    }

    public function status(): array
    {
        return [
            'id' => self::MODEL_ID,
            'kind' => 'diarization',
            'label' => 'Speaker Separation',
            'size' => '45 MiB',
            'installed' => $this->isInstalled(),
            'supported' => true,
            'runtime_memory_mb' => 256,
            'description' => 'Separates anonymous speakers locally with Sherpa-ONNX.',
        ];
    }

    /**
     * @param  callable(array<string, mixed>): void  $progress
     * @param  null|callable(): bool  $cancelled
     */
    public function download(callable $progress, ?callable $cancelled = null): void
    {
        $cancelled ??= static fn (): bool => false;
        File::ensureDirectoryExists($this->modelDirectory());

        // PharData detects bzip2 from the final extension, so keep .tar.bz2 last.
        $archive = $this->modelDirectory().'/.download-'.self::SEGMENTATION_ARCHIVE_FILE;
        $segmentation = $this->segmentationPath().'.download';
        $embedding = $this->embeddingPath().'.download';
        $temporaryPaths = [$archive, $segmentation, $embedding];

        foreach ($temporaryPaths as $path) {
            @unlink($path);
        }

        try {
            $totalBytes = self::SEGMENTATION_ARCHIVE_BYTES + self::EMBEDDING_BYTES;
            $progress(['type' => 'source', 'host' => 'github.com', 'asset' => 'speaker segmentation']);
            $this->downloadAsset(
                $this->segmentationUrl(), $archive, self::SEGMENTATION_ARCHIVE_SHA256,
                0, $totalBytes, $progress, $cancelled,
            );
            $this->extractSegmentationModel($archive, $segmentation);

            if ($cancelled()) {
                throw new RuntimeException('The speaker-separation model download was canceled.');
            }

            $progress(['type' => 'source', 'host' => 'github.com', 'asset' => 'speaker embeddings']);
            $this->downloadAsset(
                $this->embeddingUrl(), $embedding, self::EMBEDDING_SHA256,
                self::SEGMENTATION_ARCHIVE_BYTES, $totalBytes, $progress, $cancelled,
            );

            @unlink($this->segmentationPath());
            @unlink($this->embeddingPath());

            if (! rename($segmentation, $this->segmentationPath())
                || ! rename($embedding, $this->embeddingPath())) {
                throw new RuntimeException('The verified speaker-separation models could not be finalized.');
            }

            $progress([
                'type' => 'complete',
                'model' => self::MODEL_ID,
                'received_bytes' => $totalBytes,
                'total_bytes' => $totalBytes,
            ]);
        } finally {
            foreach ($temporaryPaths as $path) {
                @unlink($path);
            }
        }
    }

    private function downloadAsset(
        string $url,
        string $destinationPath,
        string $expectedSha256,
        int $completedBytes,
        int $totalBytes,
        callable $progress,
        callable $cancelled,
    ): void {
        if (! function_exists('curl_init')) {
            throw new RuntimeException('The bundled PHP cURL extension is unavailable.');
        }

        $destination = fopen($destinationPath, 'wb');

        if ($destination === false) {
            throw new RuntimeException('The speaker-separation model download file could not be opened.');
        }

        $hash = hash_init('sha256');
        $receivedBytes = 0;
        $lastReportedBytes = 0;
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => (int) config('services.speaker_diarization.download_timeout', 1800),
            CURLOPT_CAINFO => $this->http->caBundlePath(),
            CURLOPT_USERAGENT => 'AITranscriber Speaker Separation Installer',
            CURLOPT_FAILONERROR => false,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use ($destination, $hash, &$receivedBytes, &$lastReportedBytes, $completedBytes, $totalBytes, $progress, $cancelled): int {
                if ($cancelled()) {
                    return 0;
                }

                $length = strlen($chunk);

                if (fwrite($destination, $chunk) === false) {
                    return 0;
                }

                hash_update($hash, $chunk);
                $receivedBytes += $length;

                if (($receivedBytes - $lastReportedBytes) >= (512 * 1024)) {
                    $lastReportedBytes = $receivedBytes;
                    $progress([
                        'type' => 'progress',
                        'received_bytes' => $completedBytes + $receivedBytes,
                        'total_bytes' => $totalBytes,
                    ]);
                }

                return $length;
            },
        ]);

        $executed = curl_exec($handle);
        $curlError = trim(curl_error($handle));
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        curl_close($handle);
        fclose($destination);

        if ($cancelled()) {
            throw new RuntimeException('The speaker-separation model download was canceled.');
        }

        if ($executed !== true || ($status !== 0 && ($status < 200 || $status >= 300))) {
            Log::error('Sherpa diarization model download failed.', compact('url', 'status', 'curlError'));
            throw new RuntimeException($curlError !== '' ? $curlError : "Model server returned HTTP {$status}.");
        }

        $actualSha256 = hash_final($hash);

        if (! hash_equals($expectedSha256, strtolower($actualSha256))) {
            throw new RuntimeException('Speaker-separation model checksum verification failed.');
        }

        $progress([
            'type' => 'progress',
            'received_bytes' => min($totalBytes, $completedBytes + (int) filesize($destinationPath)),
            'total_bytes' => $totalBytes,
        ]);
    }

    private function extractSegmentationModel(string $archivePath, string $destinationPath): void
    {
        try {
            $archive = new PharData($archivePath);
            $entry = $archive[self::SEGMENTATION_ARCHIVE_ENTRY] ?? null;
            $contents = $entry?->getContent();
        } catch (Throwable $exception) {
            throw new RuntimeException('The speaker segmentation model archive could not be opened.', 0, $exception);
        }

        if (! is_string($contents)
            || strlen($contents) !== self::SEGMENTATION_BYTES
            || ! hash_equals(self::SEGMENTATION_SHA256, hash('sha256', $contents))) {
            throw new RuntimeException('The extracted speaker segmentation model failed verification.');
        }

        if (file_put_contents($destinationPath, $contents, LOCK_EX) === false) {
            throw new RuntimeException('The speaker segmentation model could not be installed.');
        }
    }

    private function validFile(string $path, int $bytes, string $sha256): bool
    {
        return is_file($path)
            && filesize($path) === $bytes
            && hash_equals($sha256, strtolower((string) hash_file('sha256', $path)));
    }

    private function modelDirectory(): string
    {
        $configured = trim((string) config('services.speaker_diarization.model_directory', ''));

        return $configured !== '' ? $configured : base_path('sherpa/models');
    }

    private function segmentationPath(): string
    {
        return $this->modelDirectory().'/'.self::SEGMENTATION_FILE;
    }

    private function embeddingPath(): string
    {
        return $this->modelDirectory().'/'.self::EMBEDDING_FILE;
    }

    private function segmentationUrl(): string
    {
        return trim((string) config('services.speaker_diarization.segmentation_url'));
    }

    private function embeddingUrl(): string
    {
        return trim((string) config('services.speaker_diarization.embedding_url'));
    }
}
