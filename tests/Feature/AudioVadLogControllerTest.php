<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AudioVadLogControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_project_scoped_vad_logs_with_absolute_segment_times(): void
    {
        DB::table('audio_vad_logs')->insert([
            [
                'user_id' => 1,
                'category_name' => 'Project A',
                'source_type' => 'upload',
                'clip_index' => 2,
                'clip_start_ms' => 60000,
                'clip_end_ms' => 120000,
                'range_label' => '01:00-02:00',
                'duration_ms' => 60000,
                'speech_detected' => true,
                'speech_duration_ms' => 15000,
                'segment_count' => 2,
                'speech_segments' => json_encode([
                    ['start_ms' => 3000, 'end_ms' => 10000],
                    ['start_ms' => 18000, 'end_ms' => 26000],
                ]),
                'input_name' => 'chunk_00002.wav',
                'input_size_bytes' => 1000,
                'filtered_name' => 'chunk_00002-speech.wav',
                'filtered_size_bytes' => 700,
                'status' => 'speech_detected',
                'message' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => 1,
                'category_name' => 'Project B',
                'source_type' => 'upload',
                'clip_index' => 1,
                'clip_start_ms' => 0,
                'clip_end_ms' => 60000,
                'range_label' => '00:00-01:00',
                'duration_ms' => 60000,
                'speech_detected' => false,
                'speech_duration_ms' => 0,
                'segment_count' => 0,
                'speech_segments' => json_encode([]),
                'input_name' => 'chunk_00001.wav',
                'input_size_bytes' => 1000,
                'filtered_name' => null,
                'filtered_size_bytes' => 0,
                'status' => 'no_speech',
                'message' => 'Skipped.',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $response = $this->getJson('/audio-vad-logs?category_name=Project%20A&source_type=upload');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.range_label', '01:00-02:00')
            ->assertJsonPath('data.0.speech_segments.0.absolute_start_ms', 63000)
            ->assertJsonPath('data.0.speech_segments.0.absolute_end_ms', 70000)
            ->assertJsonPath('data.0.speech_segments.1.absolute_start_ms', 78000)
            ->assertJsonPath('data.0.speech_segments.1.absolute_end_ms', 86000);
    }
}
