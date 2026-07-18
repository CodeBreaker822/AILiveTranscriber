<?php

namespace Tests\Feature;

use App\Models\AudioChunk;
use App\Models\CleanTranscriptChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TranscriptExportControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
                'transcription_timestamps' => json_encode([
                    ['speaker_id' => 'speaker_1', 'text' => 'Proceed to the next agenda.'],
                ]),
                'status' => 'transcribed',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function test_raw_text_export_returns_useful_rows_only(): void
    {
        $response = $this->postJson('/transcripts/export', [
            'category_name' => 'Meeting',
            'mode' => 'raw',
            'format' => 'txt',
        ]);

        $response->assertOk();
        $response->assertJsonStructure(['filename', 'mime_type', 'content']);
        $response->assertJsonPath('filename', 'meeting-raw-transcription.txt');
        $this->assertStringContainsString('Proceed to the next agenda.', $response->json('content'));
        $this->assertStringNotContainsString('No speech detected.', $response->json('content'));
    }

    public function test_excel_export_emits_speaker_labels(): void
    {
        $response = $this->postJson('/transcripts/export', [
            'category_name' => 'Meeting',
            'mode' => 'raw',
            'format' => 'excel',
        ]);

        $response->assertOk();
        $response->assertJsonPath('filename', 'meeting-raw-transcription.xls');
        $this->assertStringContainsString('Speaker 1', $response->json('content'));
    }

    public function test_empty_export_returns_not_found(): void
    {
        $response = $this->postJson('/transcripts/export', [
            'category_name' => 'Nonexistent',
            'mode' => 'raw',
            'format' => 'txt',
        ]);

        $response->assertNotFound();
    }

    public function test_clean_export_uses_cleaned_rows(): void
    {
        CleanTranscriptChunk::query()->create([
            'audio_chunk_id' => (int) AudioChunk::query()->where('clip_index', 2)->value('id'),
            'user_id' => 1,
            'category_name' => 'Meeting',
            'clip_index' => 2,
            'clip_start_ms' => 60000,
            'clip_end_ms' => 120000,
            'range_label' => '01:00-02:00',
            'raw_text' => 'Proceed to the next agenda.',
            'clean_text' => 'Continue with the next agenda item.',
            'clean_timestamps' => [],
            'status' => 'cleaned',
        ]);

        $response = $this->postJson('/transcripts/export', [
            'category_name' => 'Meeting',
            'mode' => 'clean',
            'format' => 'word',
        ]);

        $response->assertOk();
        $response->assertJsonPath('filename', 'meeting-clean-transcription.doc');
        $this->assertStringContainsString('Continue with the next agenda item.', $response->json('content'));
    }
}
