<?php

namespace App\Http\Controllers;

use App\Exceptions\SpeechToTextException;
use App\Services\AudioFileChunkerService;
use App\Services\ServiceUserMessage;
use App\Services\SpeechToTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AudioChunkController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::table('audio_chunks')
            ->select([
                'id',
                'clip_index',
                'clip_start_ms',
                'clip_end_ms',
                'range_label',
                'duration_ms',
                'category_name',
                'status',
                'original_name',
                'translated_text',
                'transcription_timestamps',
                'created_at',
            ])
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($row) {
                return [
                    'id' => $row->id,
                    'clip_index' => (int) $row->clip_index,
                    'clip_start_ms' => (int) $row->clip_start_ms,
                    'clip_end_ms' => (int) $row->clip_end_ms,
                    'range_label' => $row->range_label,
                    'duration_ms' => (int) $row->duration_ms,
                    'category_name' => $row->category_name ?: 'General',
                    'source_type' => $this->sourceType($row->original_name),
                    'status' => $row->status,
                    'play_url' => route('audio-chunks.audio', ['audioChunk' => $row->id]),
                    'delete_url' => route('audio-chunks.destroy', ['audioChunk' => $row->id]),
                    'translated_text' => $row->translated_text ?? null,
                    'transcription_timestamps' => $row->transcription_timestamps
                        ? json_decode($row->transcription_timestamps, true)
                        : [],
                ];
            });

        return response()->json([
            'data' => $rows,
            'count' => $rows->count(),
        ]);
    }

    private function sourceType(?string $originalName): string
    {
        return preg_match('/^chunk_\d+\.wav$/', (string) $originalName) === 1
            ? 'upload'
            : 'live';
    }

    public function store(
        Request $request,
        SpeechToTextService $speechToText,
        AudioFileChunkerService $chunker,
    ): JsonResponse
    {
        if ($request->filled('upload_session_id')) {
            return $this->storeUploadedSection($request, $speechToText, $chunker);
        }

        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:51200'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'clip_index' => ['required', 'integer', 'min:1'],
            'clip_start_ms' => ['required', 'integer', 'min:0'],
            'clip_end_ms' => ['required', 'integer', 'min:0'],
            'range_label' => ['required', 'string', 'max:32'],
            'duration_ms' => ['required', 'integer', 'min:1'],
        ]);

        $file = $request->file('audio');
        $contents = file_get_contents($file->getRealPath());

        if ($contents === false) {
            return response()->json([
                'message' => ServiceUserMessage::audioReadFailed(),
            ], 500);
        }

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);

        try {
            $transcription = $speechToText->transcribe($file);
        } catch (SpeechToTextException $exception) {
            Log::error('Live audio chunk transcription failed.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        $audioChunkId = DB::table('audio_chunks')->insertGetId([
            'user_id' => $userId,
            'category_name' => $categoryName,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
            'mime_type' => $file->getMimeType(),
            'original_name' => $file->getClientOriginalName(),
            'file_size_bytes' => $file->getSize(),
            'audio_blob' => $contents,
            'translated_text' => $transcription['text'],
            'transcription_timestamps' => json_encode($transcription['timestamps']),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'saved',
            'data' => [
                'id' => $audioChunkId,
                'user_id' => $userId,
                'category_name' => $categoryName,
                'source_type' => 'live',
                'clip_index' => (int) $validated['clip_index'],
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'clip_end_ms' => (int) $validated['clip_end_ms'],
                'range_label' => $validated['range_label'],
                'duration_ms' => (int) $validated['duration_ms'],
                'play_url' => route('audio-chunks.audio', ['audioChunk' => $audioChunkId]),
                'delete_url' => route('audio-chunks.destroy', ['audioChunk' => $audioChunkId]),
                'translated_text' => $transcription['text'],
                'transcription_timestamps' => $transcription['timestamps'],
            ],
        ], 201);
    }

    private function storeUploadedSection(
        Request $request,
        SpeechToTextService $speechToText,
        AudioFileChunkerService $chunker,
    ): JsonResponse {
        @set_time_limit(0);

        $validated = $request->validate([
            'upload_session_id' => ['required', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'clip_index' => ['required', 'integer', 'min:1'],
            'clip_start_ms' => ['required', 'integer', 'min:0'],
            'clip_end_ms' => ['required', 'integer', 'min:0'],
            'range_label' => ['required', 'string', 'max:32'],
            'duration_ms' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $segment = $chunker->extractSegment(
                $validated['upload_session_id'],
                (int) $validated['clip_index'],
                (int) $validated['clip_start_ms'],
                (int) $validated['duration_ms'],
            );

            $transcription = $speechToText->transcribe($segment['path']);
        } catch (SpeechToTextException $exception) {
            Log::error('Uploaded audio section transcription failed.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Uploaded audio section could not be processed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
            ]);

            return response()->json([
                'message' => ServiceUserMessage::audioPrepareFailed(),
            ], 500);
        }

        $contents = file_get_contents($segment['path']);

        if ($contents === false) {
            return response()->json([
                'message' => ServiceUserMessage::audioReadFailed(),
            ], 500);
        }

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);

        $audioChunkId = DB::table('audio_chunks')->insertGetId([
            'user_id' => $userId,
            'category_name' => $categoryName,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
            'mime_type' => $segment['mime_type'],
            'original_name' => $segment['name'],
            'file_size_bytes' => $segment['size'],
            'audio_blob' => $contents,
            'translated_text' => $transcription['text'],
            'transcription_timestamps' => json_encode($transcription['timestamps']),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'saved',
            'data' => [
                'id' => $audioChunkId,
                'user_id' => $userId,
                'category_name' => $categoryName,
                'source_type' => 'upload',
                'clip_index' => (int) $validated['clip_index'],
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'clip_end_ms' => (int) $validated['clip_end_ms'],
                'range_label' => $validated['range_label'],
                'duration_ms' => (int) $validated['duration_ms'],
                'prepared_duration_ms' => (int) $segment['duration_ms'],
                'prepared_file_size_bytes' => (int) $segment['size'],
                'play_url' => route('audio-chunks.audio', ['audioChunk' => $audioChunkId]),
                'delete_url' => route('audio-chunks.destroy', ['audioChunk' => $audioChunkId]),
                'translated_text' => $transcription['text'],
                'transcription_timestamps' => $transcription['timestamps'],
            ],
        ], 201);
    }

    public function audio(int $audioChunk): Response
    {
        $row = DB::table('audio_chunks')->where('id', $audioChunk)->first();

        if (! $row) {
            abort(404);
        }

        $mimeType = $row->mime_type ?: 'audio/webm';

        return response($row->audio_blob, 200)
            ->header('Content-Type', $mimeType)
            ->header('Content-Length', (string) strlen($row->audio_blob));
    }

    public function destroy(int $audioChunk): JsonResponse
    {
        $row = DB::table('audio_chunks')->where('id', $audioChunk)->first();

        if (! $row) {
            return response()->json([
                'message' => 'not found',
            ], 404);
        }

        DB::table('audio_chunks')->where('id', $audioChunk)->delete();

        return response()->json([
            'message' => 'deleted',
            'id' => $audioChunk,
        ]);
    }
}
