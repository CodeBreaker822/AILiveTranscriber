<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UpdatePackageConfigurationTest extends TestCase
{
    public function test_tauri_package_only_runs_the_update_zip_packager(): void
    {
        $package = json_decode(file_get_contents(dirname(__DIR__, 2).'/package.json'), true);

        $this->assertSame(
            'node scripts/create-update-package.mjs',
            $package['scripts']['tauri:package'] ?? null,
        );
        $this->assertSame(
            'node scripts/create-update-package.mjs empty',
            $package['scripts']['tauri:package:empty'] ?? null,
        );

        $desktopBuilder = file_get_contents(dirname(__DIR__, 2).'/scripts/build-desktop.mjs');
        $this->assertStringNotContainsString('create-update-package', $desktopBuilder);
    }

    public function test_update_payload_excludes_whisper_and_records_the_server_api_path(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/scripts/create-update-package.mjs');
        preg_match('/const commonPayload = \[(.*?)\];/s', $script, $matches);
        $payloadDeclaration = $matches[1] ?? '';

        $this->assertStringNotContainsString("'whisper'", $payloadDeclaration);
        $this->assertStringContainsString("'whisper'", $script);
        $this->assertStringContainsString("serverApiPath: '/api/transcribe/update/zipfile'", $script);
        $this->assertStringContainsString("'ggml-large-v3-turbo-q8_0.bin'", $script);
    }

    public function test_tauri_installer_resources_exclude_whisper_weights(): void
    {
        $config = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/tauri.release.conf.json'),
            true,
        );
        $resources = $config['bundle']['resources'] ?? [];

        $this->assertArrayNotHasKey('../whisper', $resources);
        $this->assertNotContains('whisper', array_values($resources), true);
    }

    public function test_windows_updater_uses_a_writable_current_user_install_and_preflights_permissions(): void
    {
        $windowsConfig = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/src-tauri/tauri.windows.conf.json'),
            true,
        );
        $rust = file_get_contents(dirname(__DIR__, 2).'/src-tauri/src/main.rs');

        $this->assertSame('currentUser', data_get($windowsConfig, 'bundle.windows.nsis.installMode'));
        $this->assertStringContainsString('.aitranscriber-update-write-test-', $rust);
        $this->assertStringContainsString('child.wait()', $rust);
        $this->assertStringContainsString('install-update.log', $rust);
    }

    public function test_bundled_php_certificate_paths_are_portable(): void
    {
        $phpIni = file_get_contents(dirname(__DIR__, 2).'/php/php.ini');

        $this->assertStringContainsString('curl.cainfo = "php/extras/ssl/cacert.pem"', $phpIni);
        $this->assertStringContainsString('openssl.cafile = "php/extras/ssl/cacert.pem"', $phpIni);
        $this->assertStringNotContainsString('C:/xampp/', $phpIni);
    }

    public function test_update_frontend_automatically_downloads_and_installs_an_available_update(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/public/js/modals/app-update.js');

        $this->assertStringContainsString("if (!payload?.available || !String(payload.version || '').trim())", $script);
        $this->assertStringContainsString("statusDownloadUrl", $script);
        $this->assertStringContainsString("requestUrl.searchParams.set('url', statusDownloadUrl)", $script);
        $this->assertStringContainsString('await downloadUpdate();', $script);
        $this->assertStringContainsString("await invoke('install_update', { archivePath });", $script);
        $this->assertStringContainsString('checkForUpdate();', $script);
    }

    public function test_native_updater_waits_for_downloaded_zip_before_installing(): void
    {
        $rust = file_get_contents(dirname(__DIR__, 2).'/src-tauri/src/main.rs');

        $this->assertStringContainsString('fn wait_for_update_archive', $rust);
        $this->assertStringContainsString('Duration::from_secs(8)', $rust);
        $this->assertStringContainsString('let archive = wait_for_update_archive(PathBuf::from(archive_path))?', $rust);
    }

    public function test_development_does_not_block_page_load_on_remote_update_checks(): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/app-layout.blade.php');
        $script = file_get_contents(dirname(__DIR__, 2).'/public/js/modals/app-update.js');
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/AppUpdateController.php');

        $this->assertStringContainsString('data-desktop-dev=', $layout);
        $this->assertStringContainsString('if (!desktopDev)', $script);
        $this->assertStringContainsString("config('app.desktop_dev') || \$api->serverIsReachable()", $controller);
    }
}
