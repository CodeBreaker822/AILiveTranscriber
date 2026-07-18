<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class TranscriptionProcessingOptionsTest extends TestCase
{
    public function test_frontend_sends_optional_vad_and_diarization_flags(): void
    {
        $root = dirname(__DIR__, 2);
        $live = file_get_contents($root.'/resources/js/live/live-controller.js');
        $upload = file_get_contents($root.'/resources/js/upload/upload-controller.js');

        $this->assertStringContainsString('const shouldUseVad = ()', $live);
        $this->assertStringContainsString('const shouldUseDiarization = ()', $live);
        $this->assertStringContainsString("formData.append('use_vad'", $live);
        $this->assertStringContainsString("formData.append('use_diarization'", $live);
        $this->assertStringContainsString('nextItem.useVad === false', $live);
        $this->assertStringContainsString('nextItem.useDiarization === false', $live);

        $this->assertStringContainsString('const shouldUseVad = ()', $upload);
        $this->assertStringContainsString('const shouldUseDiarization = ()', $upload);
        $this->assertStringContainsString('use_vad: shouldUseVad() ? 1 : 0', $upload);
        $this->assertStringContainsString('use_diarization: shouldUseDiarization() ? 1 : 0', $upload);
        $this->assertStringContainsString("formData.append('use_vad'", $upload);
        $this->assertStringContainsString("formData.append('use_diarization'", $upload);
        $this->assertStringContainsString('!shouldUseDiarization()', $upload);
    }

    public function test_backend_respects_optional_vad_and_diarization_flags(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Http/Controllers/AudioChunkController.php');
        $payloads = file_get_contents($root.'/app/Services/AudioChunk/AudioChunkPayloadService.php');
        $preparer = file_get_contents($root.'/app/Services/Audio/UploadedAudioSectionPreparationService.php');
        $live = file_get_contents($root.'/app/Services/AudioChunk/LiveAudioIngestion.php');
        $section = file_get_contents($root.'/app/Services/AudioChunk/UploadedSectionIngestion.php');
        $batch = file_get_contents($root.'/app/Services/AudioChunk/UploadedBatchIngestion.php');

        $this->assertStringContainsString("'use_vad' => ['nullable', 'boolean']", $controller);
        $this->assertStringContainsString("'use_diarization' => ['nullable', 'boolean']", $controller);
        $this->assertStringContainsString("'vad_driver' => (\$validated['use_vad'] ?? true) ? null : 'disabled'", $payloads);
        $this->assertStringContainsString("'vad_driver' => (\$validated['use_vad'] ?? true) ? null : 'disabled'", $preparer);

        $this->assertStringContainsString('$useDiarization = (bool) ($validated[\'use_diarization\'] ?? true);', $live);
        $this->assertStringContainsString('if ($useDiarization) {', $live);
        $this->assertStringContainsString('$useDiarization = (bool) ($validated[\'use_diarization\'] ?? true);', $section);
        $this->assertStringContainsString('if ($useDiarization && (int) ($validated[\'audio_chunk_id\'] ?? 0) <= 0)', $section);
        $this->assertStringContainsString('$queueOnlineDiarization = $useDiarization && $this->speakerDiarization->canDiarize();', $batch);
    }
}
