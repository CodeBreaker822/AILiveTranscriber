<?php

namespace App\Http\Controllers;

use App\Exceptions\SpeechToTextException;
use App\Services\AudioFileChunkerService;
use App\Services\ServiceUserMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class UploadedAudioTranscriptionController extends Controller
{
    public function store(
        Request $request,
        AudioFileChunkerService $chunker,
    ): JsonResponse {
        @set_time_limit(0);

        $validated = $request->validate([
            'audio_file' => ['required', 'file', 'max:5242880'],
            'chunk_seconds' => ['nullable', 'integer', 'in:60,120,300'],
        ]);

        $file = $request->file('audio_file');
        $chunkSeconds = (int) ($validated['chunk_seconds'] ?? 60);

        try {
            $session = $chunker->createSession($file);
            $sections = $chunker->buildSections($session['duration_ms'], $chunkSeconds);

            return response()->json([
                'message' => 'ready',
                'data' => [
                    'session_id' => $session['session_id'],
                    'duration_ms' => $session['duration_ms'],
                    'sections' => $sections,
                    'count' => count($sections),
                ],
            ], 201);
        } catch (SpeechToTextException $exception) {
            Log::error('Audio upload preparation failed during transcription setup.', [
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (Throwable $exception) {
            Log::error('Audio upload could not be processed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return response()->json([
                'message' => ServiceUserMessage::audioPrepareFailed(),
            ], 500);
        }
    }
}
