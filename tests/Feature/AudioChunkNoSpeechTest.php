<?php

namespace Tests\Feature;

use App\Services\AudioFileChunkerService;
use App\Services\SpeechToTextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AudioChunkNoSpeechTest extends TestCase
{
    use RefreshDatabase;

    public function test_live_chunks_without_speech_are_not_stored(): void
    {
        $segmentPath = tempnam(sys_get_temp_dir(), 'aitranscriber-live-nospeech-');
        file_put_contents($segmentPath, 'fake wav');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('prepareLiveClip')->once()->andReturn([
                'directory' => dirname($segmentPath),
                'path' => $segmentPath,
                'name' => 'live_00001.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($segmentPath),
                'duration_ms' => 60000,
            ]);
            $mock->shouldReceive('cleanup')->once();
        });

        $this->mock(SpeechToTextService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('transcribe')->once()->with($segmentPath, [
                'language_code' => 'multi',
                'clip_index' => 1,
                'clip_start_ms' => 300000,
                'clip_end_ms' => 360000,
            ])->andReturn([
                'text' => 'No speech detected.',
                'timestamps' => [],
            ]);
        });

        try {
            $response = $this->postJson('/audio-chunks', [
                'audio' => UploadedFile::fake()->create('clip.webm', 10, 'audio/webm'),
                'category_name' => 'Meeting',
                'clip_index' => 1,
                'clip_start_ms' => 300000,
                'clip_end_ms' => 360000,
                'range_label' => '05:00-06:00',
                'duration_ms' => 60000,
            ]);
        } finally {
            @unlink($segmentPath);
        }

        $response
            ->assertOk()
            ->assertJsonPath('data.skipped', true)
            ->assertJsonPath('data.reason', 'no_speech_detected');

        $this->assertDatabaseCount('audio_chunks', 0);
    }

    public function test_live_chunks_are_transcribed_from_prepared_wav_audio(): void
    {
        $segmentPath = tempnam(sys_get_temp_dir(), 'aitranscriber-live-wav-');
        file_put_contents($segmentPath, 'prepared wav bytes');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('prepareLiveClip')->once()->andReturn([
                'directory' => dirname($segmentPath),
                'path' => $segmentPath,
                'name' => 'live_00002.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($segmentPath),
                'duration_ms' => 60000,
            ]);
            $mock->shouldReceive('cleanup')->once();
        });

        $this->mock(SpeechToTextService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('transcribe')->once()->with($segmentPath, [
                'language_code' => 'tl',
                'clip_index' => 2,
                'clip_start_ms' => 60000,
                'clip_end_ms' => 120000,
            ])->andReturn([
                'text' => 'Maayong buntag.',
                'timestamps' => [],
            ]);
        });

        try {
            $response = $this->postJson('/audio-chunks', [
                'audio' => UploadedFile::fake()->create('clip.webm', 10, 'audio/webm'),
                'category_name' => 'Meeting',
                'clip_index' => 2,
                'clip_start_ms' => 60000,
                'clip_end_ms' => 120000,
                'range_label' => '01:00-02:00',
                'duration_ms' => 60000,
                'language_code' => 'tl',
            ]);
        } finally {
            @unlink($segmentPath);
        }

        $response
            ->assertCreated()
            ->assertJsonPath('data.translated_text', 'Maayong buntag.');

        $this->assertDatabaseHas('audio_chunks', [
            'category_name' => 'Meeting',
            'original_name' => 'live_00002.wav',
            'mime_type' => 'audio/wav',
            'file_size_bytes' => strlen('prepared wav bytes'),
            'translated_text' => 'Maayong buntag.',
        ]);
    }

    public function test_uploaded_sections_without_speech_are_not_stored(): void
    {
        $segmentPath = tempnam(sys_get_temp_dir(), 'aitranscriber-nospeech-');
        file_put_contents($segmentPath, 'fake audio');

        $this->mock(AudioFileChunkerService::class, function ($mock) use ($segmentPath): void {
            $mock->shouldReceive('extractSegment')->once()->andReturn([
                'path' => $segmentPath,
                'name' => 'chunk_00005.wav',
                'mime_type' => 'audio/wav',
                'size' => filesize($segmentPath),
                'duration_ms' => 60000,
            ]);
        });

        $this->mock(SpeechToTextService::class, function ($mock): void {
            $mock->shouldReceive('transcribe')->once()->andReturn([
                'text' => '',
                'timestamps' => [],
            ]);
        });

        try {
            $response = $this->postJson('/audio-chunks', [
                'upload_session_id' => 'test-session',
                'category_name' => 'Meeting',
                'clip_index' => 5,
                'clip_start_ms' => 300000,
                'clip_end_ms' => 360000,
                'range_label' => '05:00-06:00',
                'duration_ms' => 60000,
            ]);
        } finally {
            @unlink($segmentPath);
        }

        $response
            ->assertOk()
            ->assertJsonPath('data.skipped', true)
            ->assertJsonPath('data.source_type', 'upload');

        $this->assertDatabaseCount('audio_chunks', 0);
    }

    public function test_loading_chunks_deletes_existing_no_speech_rows(): void
    {
        DB::table('audio_chunks')->insert([
            [
                'user_id' => 1,
                'category_name' => 'Meeting',
                'clip_index' => 1,
                'clip_start_ms' => 0,
                'clip_end_ms' => 60000,
                'range_label' => '00:00-01:00',
                'duration_ms' => 60000,
                'mime_type' => 'audio/wav',
                'original_name' => 'chunk_00001.wav',
                'file_size_bytes' => 10,
                'audio_blob' => 'audio',
                'translated_text' => 'No speech detected.',
                'transcription_timestamps' => json_encode([]),
                'status' => 'transcribed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
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
            ],
        ]);

        $response = $this->getJson('/audio-chunks');

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.translated_text', 'Proceed to the next agenda.');

        $this->assertDatabaseMissing('audio_chunks', [
            'translated_text' => 'No speech detected.',
        ]);
    }
}
