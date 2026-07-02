<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class OfflineWhisperConfigurationTest extends TestCase
{
    public function test_auto_language_runs_transcription_instead_of_detection_only_mode(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/src-tauri/src/offline_whisper.rs');

        $this->assertStringContainsString('params.set_language(None)', $source);
        $this->assertStringNotContainsString('params.set_detect_language(true)', $source);
    }

    public function test_empty_offline_output_is_not_reported_as_success(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2).'/app/Services/OfflineWhisperService.php');

        $this->assertStringContainsString("if (\$text === '')", $source);
        $this->assertStringContainsString('returned no transcript', $source);
    }

    public function test_desktop_profiles_resources_once_and_passes_a_bounded_thread_budget(): void
    {
        $root = dirname(__DIR__, 2);
        $main = file_get_contents($root.'/src-tauri/src/main.rs');
        $whisper = file_get_contents($root.'/src-tauri/src/offline_whisper.rs');
        $service = file_get_contents($root.'/app/Services/OfflineWhisperService.php');

        $this->assertStringContainsString('struct ResourceProfile', $main);
        $this->assertStringContainsString('AI_TRANSCRIBER_WHISPER_THREADS', $main);
        $this->assertStringContainsString('AI_TRANSCRIBER_WHISPER_MEMORY_BUDGET_MB', $main);
        $this->assertStringContainsString('BELOW_NORMAL_PRIORITY_CLASS', $main);
        $this->assertStringContainsString('thread_budget.max(1)', $whisper);
        $this->assertStringNotContainsString('available_parallelism', $whisper);
        $this->assertStringContainsString('logical_processors * 3 / 5', $main);
        $this->assertStringNotContainsString('clamp(1, 6)', $main.$whisper);
        $this->assertStringContainsString("'--threads'", $service);
        $this->assertStringContainsString('Choose a smaller model', $service);
    }
}
