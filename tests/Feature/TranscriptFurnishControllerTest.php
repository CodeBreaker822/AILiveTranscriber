<?php

namespace Tests\Feature;

use App\Services\GeminiTranscriptCleanerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TranscriptFurnishControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_cleaner_window_is_skipped_without_failing_furnish(): void
    {
        DB::table('audio_chunks')->insert([
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 2,
            'clip_start_ms' => 60000,
            'clip_end_ms' => 120000,
            'range_label' => '01:00-02:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'chunk_00002.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio',
            'translated_text' => 'Proceed to the next agenda.',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->mock(GeminiTranscriptCleanerService::class, function ($mock): void {
            $mock->shouldReceive('cleanChunks')->never();
        });

        $response = $this->postJson('/transcripts/furnish', [
            'user_id' => 1,
            'category_name' => 'Meeting',
            'window_index' => 0,
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('message', 'furnished')
            ->assertJsonPath('count', 0)
            ->assertJsonPath('data', []);
    }
}
