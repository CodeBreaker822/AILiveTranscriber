<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AudioChunkController extends Controller
{
    public function index(): JsonResponse
    {
        $rows = DB::table('audio_chunks')
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
                    'status' => $row->status,
                    'play_url' => route('audio-chunks.audio', ['audioChunk' => $row->id]),
                    'delete_url' => route('audio-chunks.destroy', ['audioChunk' => $row->id]),
                    'translated_text' => null,
                ];
            });

        return response()->json([
            'data' => $rows,
            'count' => $rows->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
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
                'message' => 'failed to read audio file',
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
            'mime_type' => $file->getMimeType(),
            'original_name' => $file->getClientOriginalName(),
            'file_size_bytes' => $file->getSize(),
            'audio_blob' => $contents,
            'status' => 'stored',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return response()->json([
            'message' => 'saved',
            'data' => [
                'id' => $audioChunkId,
                'user_id' => $userId,
                'category_name' => $categoryName,
                'clip_index' => (int) $validated['clip_index'],
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'clip_end_ms' => (int) $validated['clip_end_ms'],
                'range_label' => $validated['range_label'],
                'duration_ms' => (int) $validated['duration_ms'],
                'play_url' => route('audio-chunks.audio', ['audioChunk' => $audioChunkId]),
                'delete_url' => route('audio-chunks.destroy', ['audioChunk' => $audioChunkId]),
                'translated_text' => null,
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
