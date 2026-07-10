<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class DesktopDevStartupConfigurationTest extends TestCase
{
    public function test_vite_is_ready_before_laravel_allows_tauri_to_open(): void
    {
        $root = dirname(__DIR__, 2);
        $vite = file_get_contents($root.'/vite.config.js');
        $launcher = file_get_contents($root.'/scripts/dev-local.mjs');

        $this->assertStringContainsString("host: '127.0.0.1'", $vite);
        $this->assertStringContainsString("port: 5173", $vite);
        $this->assertStringContainsString('await waitForVite();', $launcher);
        $this->assertLessThan(
            strpos($launcher, "start('Laravel server'"),
            strpos($launcher, 'await waitForVite();'),
        );
    }

    public function test_php_launchers_base_memory_budget_on_physical_ram_not_current_free_ram(): void
    {
        $root = dirname(__DIR__, 2);
        $devLauncher = file_get_contents($root.'/scripts/dev-local.mjs');
        $phpLauncher = file_get_contents($root.'/scripts/run-php.mjs');

        foreach ([$devLauncher, $phpLauncher] as $launcher) {
            $this->assertStringContainsString('Math.floor(totalMemoryMb / 2)', $launcher);
            $this->assertStringContainsString('AI_TRANSCRIBER_TOTAL_MEMORY_MB', $launcher);
            $this->assertStringContainsString('AI_TRANSCRIBER_AVAILABLE_MEMORY_MB', $launcher);
            $this->assertStringNotContainsString('availableMemoryMb * 2', $launcher);
        }
    }
}
