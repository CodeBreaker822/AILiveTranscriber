<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\File;
use RuntimeException;

class AppUpdateService
{
    public function __construct(
        private readonly HostedTranscriptionApiService $api,
    ) {
    }

    /**
     * @return array{available: bool, current_version: string, version: string, notes: string}
     */
    public function status(): array
    {
        $payload = $this->api->licenseStatus();
        $serverVersion = $this->stringValue(
            $payload['version'] ?? data_get($payload, 'update.version'),
        );
        $notes = $this->stringValue(
            $payload['notes'] ?? $payload['note'] ?? data_get($payload, 'update.notes'),
        );
        $currentVersion = $this->currentVersion();

        return [
            'available' => $serverVersion !== '' && $serverVersion !== $currentVersion,
            'current_version' => $currentVersion,
            'version' => $serverVersion,
            'notes' => $notes,
        ];
    }

    public function download(): Response
    {
        return $this->api->downloadUpdateArchive();
    }

    public function currentVersion(): string
    {
        foreach ([
            base_path('version.json'),
            base_path('build/tauri/version.json'),
            base_path('src-tauri/tauri.conf.json'),
        ] as $path) {
            if (! is_file($path)) {
                continue;
            }

            $payload = json_decode((string) file_get_contents($path), true);
            $version = is_array($payload) ? $this->stringValue($payload['version'] ?? null) : '';

            if ($version !== '') {
                return $version;
            }
        }

        return 'unknown';
    }

    public function archivePath(): string
    {
        return storage_path('app/private/app-updates/AITranscriber-update.zip');
    }

    public function prepareArchiveDirectory(): void
    {
        $directory = dirname($this->archivePath());

        if (! File::isDirectory($directory) && ! File::makeDirectory($directory, 0755, true)) {
            throw new RuntimeException('The local update directory could not be created.');
        }
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }
}
