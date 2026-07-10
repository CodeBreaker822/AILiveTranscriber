<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AudioPlaybackTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_upload_audio_is_listed_as_upload_with_play_url(): void
    {
        $id = DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 1,
            'clip_start_ms' => 0,
            'clip_end_ms' => 60000,
            'range_label' => '00:00-01:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'chunk_00001-speech.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio-data',
            'translated_text' => 'Hello from upload.',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->getJson('/audio-chunks');

        $response
            ->assertOk()
            ->assertJsonPath('data.0.id', $id)
            ->assertJsonPath('data.0.source_type', 'upload')
            ->assertJsonPath('data.0.play_url', route('audio-chunks.audio', ['audioChunk' => $id]));
    }

    public function test_audio_playback_endpoint_streams_stored_audio_bytes(): void
    {
        $id = DB::table('audio_chunks')->insertGetId([
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 2,
            'clip_start_ms' => 60000,
            'clip_end_ms' => 120000,
            'range_label' => '01:00-02:00',
            'duration_ms' => 60000,
            'mime_type' => 'audio/wav',
            'original_name' => 'live_00002-speech.wav',
            'file_size_bytes' => 10,
            'audio_blob' => 'audio-data',
            'translated_text' => 'Hello from live.',
            'transcription_timestamps' => json_encode([]),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->get(route('audio-chunks.audio', ['audioChunk' => $id]));

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertSee('audio-data', false);
    }
}
