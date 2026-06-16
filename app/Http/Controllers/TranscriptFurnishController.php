<?php

namespace App\Http\Controllers;

use App\Exceptions\GeminiTranscriptCleanerException;
use App\Services\GeminiTranscriptCleanerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TranscriptFurnishController extends Controller
{
    private const FURNISH_WINDOW_MS = 60 * 1000;

    private const GEMINI_REQUEST_INTERVAL_SECONDS = 4;

    public function store(Request $request, GeminiTranscriptCleanerService $cleaner): JsonResponse
    {
        @set_time_limit(0);

        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'window_index' => ['nullable', 'integer', 'min:0'],
        ]);

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $requestCount = 0;

        if (array_key_exists('window_index', $validated) && $validated['window_index'] !== null) {
            $windowIndex = (int) $validated['window_index'];
            $windowStartMs = $windowIndex * self::FURNISH_WINDOW_MS;
            $windowEndMs = $windowStartMs + self::FURNISH_WINDOW_MS;
            $windowChunks = $this->rawChunkQuery($userId, $categoryName)
                ->where('clip_start_ms', '>=', $windowStartMs)
                ->where('clip_start_ms', '<', $windowEndMs)
                ->get()
                ->all();

            if ($windowChunks === []) {
                return response()->json([
                    'message' => 'No raw transcript is available for this cleaner batch.',
                ], 404);
            }

            try {
                $cleaned = $this->furnishWindow($windowChunks, $cleaner, $userId, $categoryName, $requestCount);
            } catch (GeminiTranscriptCleanerException $exception) {
                return $this->furnishFailure($exception, $categoryName);
            }

            return response()->json([
                'message' => 'furnished',
                'data' => $cleaned,
                'count' => count($cleaned),
                'gemini_requests' => $requestCount,
                'window_index' => $windowIndex,
            ]);
        }

        $chunks = $this->rawChunkQuery($userId, $categoryName);

        $cleaned = [];
        $hasChunks = false;
        $currentWindow = null;
        $windowChunks = [];

        foreach ($chunks->cursor() as $chunk) {
            $hasChunks = true;
            $window = intdiv((int) $chunk->clip_start_ms, self::FURNISH_WINDOW_MS);

            if ($currentWindow !== null && $window !== $currentWindow) {
                try {
                    array_push(
                        $cleaned,
                        ...$this->furnishWindow($windowChunks, $cleaner, $userId, $categoryName, $requestCount),
                    );
                } catch (GeminiTranscriptCleanerException $exception) {
                    return $this->furnishFailure($exception, $categoryName);
                }

                $windowChunks = [];
            }

            $currentWindow = $window;
            $windowChunks[] = $chunk;
        }

        if (! $hasChunks) {
            return response()->json([
                'message' => 'No raw transcript is available to furnish.',
            ], 404);
        }

        if ($windowChunks !== []) {
            try {
                array_push(
                    $cleaned,
                    ...$this->furnishWindow($windowChunks, $cleaner, $userId, $categoryName, $requestCount),
                );
            } catch (GeminiTranscriptCleanerException $exception) {
                return $this->furnishFailure($exception, $categoryName);
            }
        }

        return response()->json([
            'message' => 'furnished',
            'data' => $cleaned,
            'count' => count($cleaned),
            'gemini_requests' => $requestCount,
        ]);
    }

    private function rawChunkQuery(int $userId, string $categoryName)
    {
        return DB::table('audio_chunks')
            ->select([
                'id',
                'user_id',
                'category_name',
                'clip_index',
                'clip_start_ms',
                'clip_end_ms',
                'range_label',
                'translated_text',
                'transcription_timestamps',
            ])
            ->where('user_id', $userId)
            ->where('category_name', $categoryName)
            ->orderBy('clip_start_ms');
    }

    private function furnishWindow(
        array $windowChunks,
        GeminiTranscriptCleanerService $cleaner,
        int $userId,
        string $categoryName,
        int &$requestCount,
    ): array {
        if ($this->windowHasText($windowChunks) && $requestCount > 0) {
            sleep(self::GEMINI_REQUEST_INTERVAL_SECONDS);
        }

        $existing = $this->existingCleanedChunks($windowChunks);
        $chunksToClean = array_values(array_filter(
            $windowChunks,
            fn ($chunk): bool => ! $existing->has((int) $chunk->id),
        ));
        $result = [
            'chunks' => [],
            'model' => $existing->first() ? $existing->first()->model : null,
        ];

        if ($chunksToClean !== []) {
            $result = $cleaner->cleanChunks($this->toGeminiChunks($chunksToClean));

            if ($this->windowHasText($chunksToClean)) {
                $requestCount++;
            }
        }

        $newCleanedById = collect($result['chunks'])->keyBy('audio_chunk_id');

        $cleaned = [];

        foreach ($windowChunks as $chunk) {
            $existingChunk = $existing->get((int) $chunk->id);

            if ($existingChunk) {
                $cleaned[] = $this->cleanedResponseRow($chunk, [
                    'text' => (string) ($existingChunk->clean_text ?? ''),
                    'timestamps' => $this->decodeTimestamps($existingChunk->clean_timestamps),
                    'model' => $existingChunk->model,
                ]);

                continue;
            }

            $cleanedChunk = $newCleanedById->get((int) $chunk->id);

            if (! $cleanedChunk) {
                continue;
            }

            DB::table('clean_transcript_chunks')->updateOrInsert(
                ['audio_chunk_id' => $chunk->id],
                [
                    'user_id' => $userId,
                    'category_name' => $categoryName,
                    'clip_index' => (int) $chunk->clip_index,
                    'clip_start_ms' => (int) $chunk->clip_start_ms,
                    'clip_end_ms' => (int) $chunk->clip_end_ms,
                    'range_label' => $chunk->range_label,
                        'raw_text' => $chunk->translated_text,
                        'clean_text' => $cleanedChunk['text'],
                        'clean_timestamps' => json_encode($cleanedChunk['timestamps']),
                        'model' => $result['model'] ?? null,
                        'status' => 'cleaned',
                        'updated_at' => now(),
                        'created_at' => now(),
                ],
            );

            $cleaned[] = $this->cleanedResponseRow($chunk, [
                'text' => $cleanedChunk['text'],
                'timestamps' => $cleanedChunk['timestamps'],
                'model' => $result['model'] ?? null,
            ]);
        }

        return $cleaned;
    }

    private function existingCleanedChunks(array $windowChunks)
    {
        $ids = array_map(fn ($chunk): int => (int) $chunk->id, $windowChunks);

        if ($ids === []) {
            return collect();
        }

        return DB::table('clean_transcript_chunks')
            ->whereIn('audio_chunk_id', $ids)
            ->get()
            ->keyBy('audio_chunk_id');
    }

    private function cleanedResponseRow($chunk, array $cleanedChunk): array
    {
        return [
            'audio_chunk_id' => $chunk->id,
            'clip_index' => (int) $chunk->clip_index,
            'clip_start_ms' => (int) $chunk->clip_start_ms,
            'clip_end_ms' => (int) $chunk->clip_end_ms,
            'range_label' => $chunk->range_label,
            'clean_text' => $cleanedChunk['text'],
            'clean_timestamps' => $cleanedChunk['timestamps'],
            'model' => $cleanedChunk['model'],
        ];
    }

    private function furnishFailure(GeminiTranscriptCleanerException $exception, string $categoryName): JsonResponse
    {
        Log::error('Transcript furnishing failed.', [
            'message' => $exception->getMessage(),
            'category_name' => $categoryName,
        ]);

        return response()->json([
            'message' => $exception->getMessage(),
        ], 422);
    }

    private function toGeminiChunks(array $chunks): array
    {
        return array_map(
            fn ($chunk): array => [
                'id' => (int) $chunk->id,
                'range_label' => $chunk->range_label,
                'text' => (string) ($chunk->translated_text ?? ''),
                'timestamps' => $this->decodeTimestamps($chunk->transcription_timestamps),
            ],
            $chunks,
        );
    }

    private function windowHasText(array $chunks): bool
    {
        foreach ($chunks as $chunk) {
            if (trim((string) ($chunk->translated_text ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    private function decodeTimestamps(?string $timestamps): array
    {
        if (! $timestamps) {
            return [];
        }

        $decoded = json_decode($timestamps, true);

        return is_array($decoded) ? $decoded : [];
    }
}
