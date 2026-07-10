<?php

namespace Tests\Feature;

use App\Jobs\DiarizeUploadedAudioBatch;
use App\Services\SpeakerDiarizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class QueuedUploadedAudioDiarizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_merges_only_the_audio_chunks_named_in_its_queue_payload(): void
    {
        $queuedId = $this->insertAudioChunk(1, 'Raw queued transcript', 'diarization_queued');
        $untouchedId = $this->insertAudioChunk(2, 'Another raw transcript', 'diarization_queued');
        $diarization = Mockery::mock(SpeakerDiarizationService::class);
        $diarization->shouldReceive('apply')
            ->once()
            ->withArgs(fn (string $audioPath, array $transcription, array $options): bool => is_file($audioPath)
                && $transcription['text'] === 'Raw queued transcript'
                && $options['clip_start_ms'] === 0
                && $options['speaker_session_id'] === 'upload-session-1')
            ->andReturn([
                'text' => 'Speaker 1: Raw queued transcript',
                'timestamps' => [['text' => 'Raw queued transcript', 'start' => 0, 'end' => 1, 'speaker_id' => 'speaker_1']],
            ]);
        $diarization->shouldReceive('releaseSession')->once()->with('upload-session-1');

        (new DiarizeUploadedAudioBatch([$queuedId], 'upload-session-1', true))->handle($diarization);

        $queued = DB::table('audio_chunks')->where('id', $queuedId)->first();
        $untouched = DB::table('audio_chunks')->where('id', $untouchedId)->first();

        $this->assertSame('Speaker 1: Raw queued transcript', $queued->translated_text);
        $this->assertSame('transcribed', $queued->status);
        $this->assertSame('Another raw transcript', $untouched->translated_text);
        $this->assertSame('diarization_queued', $untouched->status);
    }

    public function test_online_upload_uses_a_durable_queue_without_changing_live_or_offline_diarization(): void
    {
        $root = dirname(__DIR__, 2);
        $controller = file_get_contents($root.'/app/Http/Controllers/AudioChunkController.php');
        $frontend = file_get_contents($root.'/resources/js/app.js');
        $speechToText = file_get_contents($root.'/app/Services/SpeechToTextService.php');
        $tauri = file_get_contents($root.'/src-tauri/src/main.rs');

        $this->assertStringContainsString('DiarizeUploadedAudioBatch::dispatch(', $controller);
        $this->assertStringContainsString('$queueOnlineDiarization ? \'diarization_queued\' : \'transcribed\'', $controller);
        $this->assertStringNotContainsString('diarizationBatchProcess(', $controller);
        $this->assertStringContainsString("'transcription_engine' => ['nullable', 'string', 'in:online']", $controller);
        $this->assertGreaterThanOrEqual(2, substr_count($controller, '$speakerDiarization->apply('));
        $this->assertStringContainsString("if (audioChunkBatchUrl && getTranscriptionEngine() === 'online')", $frontend);
        $this->assertStringNotContainsString('$this->offlineWhisper->transcribe($clip', $speechToText);
        $this->assertStringContainsString('return $this->api->transcribeBatch($clips, $options);', $speechToText);
        $this->assertStringContainsString('monitorQueuedDiarization(queuedDiarizationIds);', $frontend);
        $this->assertStringContainsString('.arg("queue:work")', $tauri);
        $this->assertStringContainsString('queue_worker: Mutex<Option<Child>>', $tauri);
    }

    private function insertAudioChunk(int $clipIndex, string $text, string $status): int
    {
        return DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Queued upload',
            'clip_index' => $clipIndex,
            'clip_start_ms' => ($clipIndex - 1) * 1000,
            'clip_end_ms' => $clipIndex * 1000,
            'range_label' => '00:00-00:01',
            'duration_ms' => 1000,
            'mime_type' => 'audio/wav',
            'original_name' => "chunk_{$clipIndex}-speech.wav",
            'file_size_bytes' => 48,
            'audio_blob' => str_repeat("\0", 48),
            'translated_text' => $text,
            'transcription_timestamps' => json_encode([['text' => $text, 'start' => 0, 'end' => 1]]),
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
