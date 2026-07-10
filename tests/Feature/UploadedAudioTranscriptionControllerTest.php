<?php

namespace Tests\Feature;

use App\Http\Controllers\UploadedAudioTranscriptionController;
use App\Services\AudioFileChunkerService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class UploadedAudioTranscriptionControllerTest extends TestCase
{
    public function test_uploaded_audio_chunk_selection_rejects_lengths_above_twenty_minutes(): void
    {
        $request = Request::create('/audio-uploads', 'POST', [
            'local_path' => __FILE__,
            'chunk_seconds' => 1500,
        ]);
        $chunker = $this->mock(AudioFileChunkerService::class, function ($mock): void {
            $mock->shouldReceive('createSessionFromPath')->never();
            $mock->shouldReceive('createSession')->never();
        });

        try {
            app(UploadedAudioTranscriptionController::class)->store($request, $chunker);
            $this->fail('Expected chunk_seconds above twenty minutes to fail validation.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('chunk_seconds', $exception->errors());
        }
    }
}
