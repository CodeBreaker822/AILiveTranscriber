<?php

namespace App\Http\Controllers;

use App\Exceptions\SpeechToTextException;
use App\Services\AudioFileChunkerService;
use App\Services\ServiceUserMessage;
use App\Services\SpeechAudioFilterService;
use App\Services\SpeechToTextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AudioChunkController extends Controller
{
    public function index(): JsonResponse
    {
        $this->deleteNoSpeechRows();

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
        return preg_match('/^chunk_\d+(?:-speech)?\.wav$/', (string) $originalName) === 1
            ? 'upload'
            : 'live';
    }

    public function store(
        Request $request,
        SpeechToTextService $speechToText,
        AudioFileChunkerService $chunker,
        SpeechAudioFilterService $speechFilter,
    ): JsonResponse
    {
        if ($request->filled('upload_session_id')) {
            return $this->storeUploadedSection($request, $speechToText, $chunker, $speechFilter);
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
            'language_code' => ['nullable', 'string', 'max:32'],
            'transcription_engine' => ['nullable', 'string', 'in:online,offline'],
            'whisper_model' => ['nullable', 'string', 'in:tiny,small,medium,large,turbo'],
        ]);

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $file = $request->file('audio');
        $preparedClip = null;

        try {
            $preparedClip = $chunker->prepareLiveClip($file, (int) $validated['clip_index']);
            $speechAudio = $speechFilter->prepare($preparedClip, $this->vadContext($validated, $userId, $categoryName, 'live'));

            if (! $speechAudio['speech_detected']) {
                if (is_array($preparedClip) && isset($preparedClip['directory'])) {
                    $chunker->cleanup((string) $preparedClip['directory']);
                }

                return response()->json([
                    'message' => 'skipped',
                    'data' => $this->skippedResponseData($validated, 'live', [
                        'vad' => $speechAudio['vad'],
                    ]),
                ]);
            }

            $transcriptionAudio = $speechAudio['audio'];
            $transcription = $speechToText->transcribe($transcriptionAudio['path'], [
                'language_code' => $validated['language_code'] ?? 'multi',
                'clip_index' => (int) $validated['clip_index'],
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'clip_end_ms' => (int) $validated['clip_end_ms'],
                ...(isset($validated['transcription_engine']) ? ['engine' => $validated['transcription_engine']] : []),
                ...(isset($validated['whisper_model']) ? ['model' => $validated['whisper_model']] : []),
            ]);
        } catch (SpeechToTextException $exception) {
            if (is_array($preparedClip) && isset($preparedClip['directory'])) {
                $chunker->cleanup((string) $preparedClip['directory']);
            }

            Log::error('Live audio chunk transcription failed.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
                'language_code' => $validated['language_code'] ?? 'multi',
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            if (is_array($preparedClip) && isset($preparedClip['directory'])) {
                $chunker->cleanup((string) $preparedClip['directory']);
            }

            Log::error('Live audio chunk could not be prepared.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
                'language_code' => $validated['language_code'] ?? 'multi',
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        if ($this->isNoSpeechTranscript($transcription['text'] ?? '')) {
            if (is_array($preparedClip) && isset($preparedClip['directory'])) {
                $chunker->cleanup((string) $preparedClip['directory']);
            }

            return response()->json([
                'message' => 'skipped',
                'data' => $this->skippedResponseData($validated, 'live'),
            ]);
        }

        $storedAudio = $transcriptionAudio ?? $preparedClip;
        $contents = is_array($storedAudio) ? file_get_contents($storedAudio['path']) : false;

        if ($contents === false) {
            if (is_array($preparedClip) && isset($preparedClip['directory'])) {
                $chunker->cleanup((string) $preparedClip['directory']);
            }

            return response()->json([
                'message' => ServiceUserMessage::audioReadFailed(),
            ], 500);
        }

        $audioChunkId = DB::table('audio_chunks')->insertGetId([
            'user_id' => $userId,
            'category_name' => $categoryName,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
            'mime_type' => $storedAudio['mime_type'],
            'original_name' => $storedAudio['name'],
            'file_size_bytes' => $storedAudio['size'],
            'audio_blob' => $contents,
            'translated_text' => $transcription['text'],
            'transcription_timestamps' => json_encode($transcription['timestamps']),
            'status' => 'transcribed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if (is_array($preparedClip) && isset($preparedClip['directory'])) {
            $chunker->cleanup((string) $preparedClip['directory']);
        }

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
        SpeechAudioFilterService $speechFilter,
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
            'language_code' => ['nullable', 'string', 'max:32'],
            'transcription_engine' => ['nullable', 'string', 'in:online,offline'],
            'whisper_model' => ['nullable', 'string', 'in:tiny,small,medium,large,turbo'],
        ]);

        try {
            $segment = $chunker->extractSegment(
                $validated['upload_session_id'],
                (int) $validated['clip_index'],
                (int) $validated['clip_start_ms'],
                (int) $validated['duration_ms'],
            );
            $userId = (int) ($validated['user_id'] ?? 1);
            $categoryName = trim((string) $validated['category_name']);
            $speechAudio = $speechFilter->prepare($segment, $this->vadContext($validated, $userId, $categoryName, 'upload'));

            if (! $speechAudio['speech_detected']) {
                return response()->json([
                    'message' => 'skipped',
                    'data' => $this->skippedResponseData($validated, 'upload', [
                        'prepared_duration_ms' => (int) $segment['duration_ms'],
                        'prepared_file_size_bytes' => (int) $segment['size'],
                        'vad' => $speechAudio['vad'],
                    ]),
                ]);
            }

            $transcriptionAudio = $speechAudio['audio'];
            $transcription = $speechToText->transcribe($transcriptionAudio['path'], [
                'language_code' => $validated['language_code'] ?? 'multi',
                'clip_index' => (int) $validated['clip_index'],
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'clip_end_ms' => (int) $validated['clip_end_ms'],
                ...(isset($validated['transcription_engine']) ? ['engine' => $validated['transcription_engine']] : []),
                ...(isset($validated['whisper_model']) ? ['model' => $validated['whisper_model']] : []),
            ]);
        } catch (SpeechToTextException $exception) {
            Log::error('Uploaded audio section transcription failed.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
                'language_code' => $validated['language_code'] ?? 'multi',
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            Log::error('Uploaded audio section could not be prepared.', [
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

        if ($this->isNoSpeechTranscript($transcription['text'] ?? '')) {
            return response()->json([
                'message' => 'skipped',
                'data' => $this->skippedResponseData($validated, 'upload', [
                    'prepared_duration_ms' => (int) $segment['duration_ms'],
                    'prepared_file_size_bytes' => (int) $segment['size'],
                ]),
            ]);
        }

        $storedAudio = $transcriptionAudio ?? $segment;
        $contents = file_get_contents($storedAudio['path']);

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
            'mime_type' => $storedAudio['mime_type'],
            'original_name' => $storedAudio['name'],
            'file_size_bytes' => $storedAudio['size'],
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
                'prepared_duration_ms' => (int) $storedAudio['duration_ms'],
                'prepared_file_size_bytes' => (int) $storedAudio['size'],
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

    private function vadContext(array $validated, int $userId, string $categoryName, string $sourceType): array
    {
        return [
            'user_id' => $userId,
            'category_name' => $categoryName,
            'source_type' => $sourceType,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => (string) $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
        ];
    }

    private function skippedResponseData(array $validated, string $sourceType, array $extra = []): array
    {
        return array_merge([
            'skipped' => true,
            'reason' => 'no_speech_detected',
            'source_type' => $sourceType,
            'clip_index' => (int) $validated['clip_index'],
            'clip_start_ms' => (int) $validated['clip_start_ms'],
            'clip_end_ms' => (int) $validated['clip_end_ms'],
            'range_label' => $validated['range_label'],
            'duration_ms' => (int) $validated['duration_ms'],
        ], $extra);
    }

    private function deleteNoSpeechRows(): void
    {
        DB::table('audio_chunks')
            ->whereNotNull('translated_text')
            ->where(function ($query): void {
                $query
                    ->whereRaw("LOWER(TRIM(translated_text)) = 'no speech detected.'")
                    ->orWhereRaw("LOWER(TRIM(translated_text)) = 'no speech detected'")
                    ->orWhereRaw("TRIM(translated_text) = ''");
            })
            ->delete();
    }

    private function isNoSpeechTranscript(?string $text): bool
    {
        $normalized = strtolower(trim((string) $text));

        return in_array($normalized, ['', 'no speech detected', 'no speech detected.'], true);
    }
}
