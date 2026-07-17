<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class UpdatePackageConfigurationTest extends TestCase
{
    public function test_tauri_package_uses_official_updater_artifact_build(): void
    {
        $package = json_decode(file_get_contents(dirname(__DIR__, 2).'/package.json'), true);

        $this->assertSame(
            'node scripts/build-desktop.mjs',
            $package['scripts']['tauri:package'] ?? null,
        );
        $this->assertSame(
            'node scripts/build-desktop.mjs empty',
            $package['scripts']['tauri:package:empty'] ?? null,
        );

        $desktopBuilder = file_get_contents(dirname(__DIR__, 2).'/scripts/build-desktop.mjs');
        $this->assertStringNotContainsString('create-update-package', $desktopBuilder);
        $this->assertStringContainsString('tauri.release.conf.json', $desktopBuilder);
    }

    public function test_update_payload_excludes_whisper_and_records_the_server_api_path(): void
    {
        $script = file_get_contents(dirname(__DIR__, 2).'/scripts/create-update-package.mjs');
        preg_match('/const commonPayload = \[(.*?)\];/s', $script, $matches);
        $payloadDeclaration = $matches[1] ?? '';

        $this->assertStringNotContainsString("'.git'", $payloadDeclaration);
        $this->assertStringNotContainsString("'whisper'", $payloadDeclaration);
        $this->assertStringNotContainsString("'.git-broken'", $payloadDeclaration);
        $this->assertStringContainsString("filter: (sourcePath) => !isGitMetadataPath(sourcePath)", $script);
        $this->assertStringContainsString("['.git', '.git-broken']", $script);
        $this->assertStringContainsString("'whisper'", $script);
        $this->assertStringContainsString("serverApiPath: '/api/transcribe/update/zipfile'", $script);
        $this->assertStringContainsString("'ggml-large-v3-turbo-q8_0.bin'", $script);
        $this->assertStringNotContainsString("'vulkan-1.dll'", $script);
    }

    public function test_generated_database_and_process_artifacts_stay_out_of_git_and_update_packages(): void
    {
        $root = dirname(__DIR__, 2);
        $gitignore = file_get_contents($root.'/.gitignore');
        $packager = file_get_contents($root.'/scripts/create-update-package.mjs');

        $this->assertStringContainsString('/database/database.sqlite', $gitignore);
        $this->assertStringContainsString('/storage/framework/process-temp/', $gitignore);
        $this->assertStringContainsString("normalized === 'database/database.sqlite'", $packager);
        $this->assertStringContainsString('entry.name.toLowerCase() === \'database.sqlite\'', $packager);
        $this->assertStringContainsString('storage/', $packager);
    }

    public function test_tauri_installer_resources_exclude_whisper_weights(): void
    {
        $config = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/tauri.release.conf.json'),
            true,
        );
        $resources = $config['bundle']['resources'] ?? [];

        $this->assertArrayNotHasKey('../.git', $resources);
        $this->assertArrayNotHasKey('../whisper', $resources);
        $this->assertArrayNotHasKey('../.git-broken', $resources);
        $this->assertNotContains('.git', array_values($resources), true);
        $this->assertNotContains('whisper', array_values($resources), true);
        $this->assertNotContains('.git-broken', array_values($resources), true);
        $this->assertArrayNotHasKey('target/release/vulkan-1.dll', $resources);
    }

    public function test_tauri_official_updater_is_configured_for_signed_windows_updates(): void
    {
        $tauriConfig = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/src-tauri/tauri.conf.json'),
            true,
        );
        $windowsConfig = json_decode(
            file_get_contents(dirname(__DIR__, 2).'/src-tauri/tauri.windows.conf.json'),
            true,
        );
        $rust = file_get_contents(dirname(__DIR__, 2).'/src-tauri/src/main.rs');

        $this->assertSame('1.0.0', $tauriConfig['version']);
        $this->assertTrue($tauriConfig['bundle']['createUpdaterArtifacts'] ?? false);
        $this->assertNotEmpty(data_get($tauriConfig, 'plugins.updater.pubkey'));
        $this->assertSame(
            ['https://github.com/CodeBreaker822/AITranscriberAPP/releases/latest/download/latest.json'],
            data_get($tauriConfig, 'plugins.updater.endpoints'),
        );
        $this->assertSame('passive', data_get($tauriConfig, 'plugins.updater.windows.installMode'));
        $this->assertSame('currentUser', data_get($windowsConfig, 'bundle.windows.nsis.installMode'));
        $this->assertStringContainsString('tauri_plugin_updater::Builder::new().build()', $rust);
        $this->assertStringContainsString('tauri_plugin_updater::{Update, UpdaterExt}', $rust);
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

        $this->assertStringContainsString("await invoke('check_app_update');", $script);
        $this->assertStringContainsString("if (!payload?.available || !String(payload.version || '').trim())", $script);
        $this->assertStringContainsString("listen('app-update-progress'", $script);
        $this->assertStringContainsString('await downloadUpdate();', $script);
        $this->assertStringContainsString("await invoke('install_update');", $script);
        $this->assertStringContainsString('checkForUpdate();', $script);
        $this->assertStringNotContainsString('data.updateDownloadUrl', $script);
        $this->assertStringNotContainsString('/app-update/download', $script);
    }

    public function test_native_updater_downloads_signed_artifact_before_installing(): void
    {
        $rust = file_get_contents(dirname(__DIR__, 2).'/src-tauri/src/main.rs');

        $this->assertStringContainsString('.check()', $rust);
        $this->assertStringContainsString('.download(', $rust);
        $this->assertStringContainsString('.install(bytes)', $rust);
        $this->assertStringContainsString('stop_laravel(&app);', $rust);
        $this->assertStringNotContainsString('wait_for_update_archive', $rust);
        $this->assertStringNotContainsString('install-update.ps1', $rust);
    }

    public function test_development_does_not_block_page_load_on_remote_update_checks(): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2).'/resources/views/components/app-layout.blade.php');
        $script = file_get_contents(dirname(__DIR__, 2).'/public/js/modals/app-update.js');
        $controller = file_get_contents(dirname(__DIR__, 2).'/app/Http/Controllers/AppUpdateController.php');

        $this->assertStringContainsString('data-desktop-dev=', $layout);
        $this->assertStringContainsString('if (!desktopDev)', $script);
        $this->assertStringContainsString("config('app.desktop_dev') || \$api->serverIsReachable()", $controller);
        $this->assertStringNotContainsString('data-update-status-url', $layout);
        $this->assertStringNotContainsString('data-update-download-url', $layout);
    }
}
