<?php

namespace App\Http\Controllers;

use App\Exceptions\SpeechToTextException;
use App\Services\AppSettingsService;
use App\Services\AudioFileChunkerService;
use App\Services\ServiceUserMessage;
use App\Services\SpeakerDiarizationService;
use App\Services\SpeechAudioFilterService;
use App\Services\SpeechToTextService;
use App\Services\UploadedAudioSectionPreparationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

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
        SpeakerDiarizationService $speakerDiarization,
    ): JsonResponse
    {
        if ($request->filled('upload_session_id')) {
            return $this->storeUploadedSection($request, $speechToText, $chunker, $speechFilter, $speakerDiarization);
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
            'speaker_session_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'progress_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'finalize_session' => ['nullable', 'boolean'],
        ]);

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $speakerSessionId = trim((string) ($validated['speaker_session_id'] ?? ''));
        $finalizeSession = (bool) ($validated['finalize_session'] ?? false);
        $file = $request->file('audio');
        $preparedClip = null;

        try {
            $preparedClip = $chunker->prepareLiveClip($file, (int) $validated['clip_index']);
            $speechAudio = $speechFilter->prepare($preparedClip, $this->vadContext($validated, $userId, $categoryName, 'live'));

            if (! $speechAudio['speech_detected']) {
                if ($finalizeSession) {
                    $speechToText->releaseOfflineWorker([
                        'engine' => $validated['transcription_engine'] ?? 'online',
                    ]);
                    $speakerDiarization->releaseSession($speakerSessionId);
                }
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
                ...(isset($validated['progress_id']) ? ['progress_id' => $validated['progress_id']] : []),
                ...($finalizeSession ? ['release_worker' => true] : []),
            ]);
            $transcription = $speakerDiarization->apply($transcriptionAudio['path'], $transcription, [
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'speaker_session_id' => $speakerSessionId,
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
            if ($finalizeSession) {
                $speakerDiarization->releaseSession($speakerSessionId);
            }
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
        if ($finalizeSession) {
            $speakerDiarization->releaseSession($speakerSessionId);
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

    public function prepareUploadedSection(
        Request $request,
        UploadedAudioSectionPreparationService $preparer,
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
            return response()->json([
                'message' => 'prepared',
                'data' => $preparer->prepare($validated),
            ]);
        } catch (RuntimeException $exception) {
            Log::error('Uploaded audio section could not be prepared.', [
                'message' => $exception->getMessage(),
                'clip_index' => (int) $validated['clip_index'],
                'range_label' => $validated['range_label'],
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function prepareUploadedSectionsBatch(Request $request): JsonResponse
    {
        @set_time_limit(0);

        $validated = $request->validate([
            'upload_session_id' => ['required', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'concurrency' => ['nullable', 'integer', 'min:1', 'max:64'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.clip_index' => ['required', 'integer', 'min:1'],
            'sections.*.clip_start_ms' => ['required', 'integer', 'min:0'],
            'sections.*.clip_end_ms' => ['required', 'integer', 'min:0'],
            'sections.*.range_label' => ['required', 'string', 'max:32'],
            'sections.*.duration_ms' => ['required', 'integer', 'min:1'],
        ]);

        $sections = array_values($validated['sections']);
        $requestedConcurrency = (int) ($validated['concurrency'] ?? count($sections));
        $processConcurrencyLimit = max(1, (int) config('services.upload_prepare.process_concurrency', 2));
        $concurrency = max(1, min(
            $requestedConcurrency,
            count($sections),
            $processConcurrencyLimit,
        ));

        Log::info('Uploaded audio batch preparation started.', [
            'section_count' => count($sections),
            'requested_concurrency' => $requestedConcurrency,
            'process_concurrency_limit' => $processConcurrencyLimit,
            'effective_concurrency' => $concurrency,
        ]);

        $pending = array_map(function (array $section, int $index) use ($validated): array {
            return [
                'index' => $index,
                'payload' => [
                    'upload_session_id' => $validated['upload_session_id'],
                    'user_id' => (int) ($validated['user_id'] ?? 1),
                    'category_name' => trim((string) $validated['category_name']),
                    ...$section,
                ],
            ];
        }, $sections, array_keys($sections));
        $running = [];
        $prepared = [];

        try {
            while ($pending !== [] || $running !== []) {
                while (count($running) < $concurrency && $pending !== []) {
                    $job = array_shift($pending);
                    $process = $this->prepareSectionProcess($job['payload']);
                    $process->start();
                    $running[] = [
                        'index' => $job['index'],
                        'process' => $process,
                    ];
                }

                foreach ($running as $key => $job) {
                    /** @var Process $process */
                    $process = $job['process'];

                    if ($process->isRunning()) {
                        continue;
                    }

                    unset($running[$key]);
                    $payload = json_decode(trim($process->getOutput()), true);

                    if (! $process->isSuccessful() || ! is_array($payload)) {
                        $message = is_array($payload) && is_string($payload['message'] ?? null)
                            ? $payload['message']
                            : trim($process->getErrorOutput());

                        throw new RuntimeException($message !== '' ? $message : 'Audio section could not be prepared.');
                    }

                    if (($payload['ok'] ?? false) !== true || ! is_array($payload['data'] ?? null)) {
                        throw new RuntimeException((string) ($payload['message'] ?? 'Audio section could not be prepared.'));
                    }

                    $prepared[] = [
                        'index' => (int) $job['index'],
                        ...$payload['data'],
                    ];
                }

                if ($running !== []) {
                    usleep(40_000);
                }
            }
        } catch (RuntimeException $exception) {
            foreach ($running as $job) {
                /** @var Process $process */
                $process = $job['process'];
                $process->stop(0);
            }

            Log::error('Uploaded audio batch preparation failed.', [
                'message' => $exception->getMessage(),
                'section_count' => count($sections),
                'requested_concurrency' => $requestedConcurrency,
                'process_concurrency_limit' => $processConcurrencyLimit,
                'effective_concurrency' => $concurrency,
            ]);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        usort($prepared, fn (array $first, array $second): int => $first['index'] <=> $second['index']);
        $prepared = array_map(function (array $item): array {
            unset($item['index']);

            return $item;
        }, $prepared);

        return response()->json([
            'message' => 'prepared',
            'data' => $prepared,
            'concurrency' => $concurrency,
            'requested_concurrency' => $requestedConcurrency,
        ]);
    }

    private function prepareSectionProcess(array $payload): Process
    {
        $encoded = base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $phpBinary = is_file(base_path('php/php.exe'))
            ? base_path('php/php.exe')
            : PHP_BINARY;
        $process = new Process([
            $phpBinary,
            base_path('artisan'),
            'app:prepare-upload-section',
            '--payload='.$encoded,
        ], base_path());
        $process->setTimeout(null);

        return $process;
    }

    private function diarizationBatchProcess(array $batch, string $speakerSessionId): Process
    {
        $payload = [
            'speaker_session_id' => $speakerSessionId,
            'clips' => array_values(array_map(
                fn (array $item): array => [
                    'audio_path' => $item['audio']['path'],
                    'clip_index' => (int) $item['section']['clip_index'],
                    'clip_start_ms' => (int) $item['section']['clip_start_ms'],
                ],
                $batch,
            )),
        ];
        $encoded = base64_encode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $phpBinary = is_file(base_path('php/php.exe'))
            ? base_path('php/php.exe')
            : PHP_BINARY;
        $process = new Process([
            $phpBinary,
            base_path('artisan'),
            'app:diarize-upload-batch',
            '--payload='.$encoded,
        ], base_path());
        $process->setTimeout(null);

        return $process;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>|null
     */
    private function collectDiarizationBatch(?Process $process): ?array
    {
        if ($process === null) {
            return [];
        }

        $process->wait();
        $payload = json_decode(trim($process->getOutput()), true);

        if (! $process->isSuccessful() || ! is_array($payload) || ($payload['ok'] ?? false) !== true) {
            Log::warning('Async speaker diarization did not complete cleanly.', [
                'message' => is_array($payload) ? ($payload['message'] ?? null) : null,
                'error' => trim($process->getErrorOutput()),
            ]);

            return null;
        }

        $segments = [];

        foreach (array_values(array_filter($payload['data'] ?? [], 'is_array')) as $queueIndex => $item) {
            $clipSegments = array_values(array_filter($item['segments'] ?? [], 'is_array'));

            if (isset($item['clip_index'])) {
                $segments['clip:'.((int) $item['clip_index'])] = $clipSegments;
            }

            $segments['queue:'.$queueIndex] = $clipSegments;
        }

        return $segments;
    }

    private function stopDiarizationProcess(?Process $process): void
    {
        if ($process !== null && $process->isRunning()) {
            $process->stop(0);
        }
    }

    public function storeBatch(
        Request $request,
        SpeechToTextService $speechToText,
        AudioFileChunkerService $chunker,
        SpeakerDiarizationService $speakerDiarization,
        AppSettingsService $settings,
    ): JsonResponse {
        @set_time_limit(0);

        $validated = $request->validate([
            'upload_session_id' => ['required', 'string', 'max:80'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'category_name' => ['required', 'string', 'max:120'],
            'language_code' => ['nullable', 'string', 'max:32'],
            'transcription_engine' => ['nullable', 'string', 'in:online,offline'],
            'whisper_model' => ['nullable', 'string', 'in:tiny,small,medium,large,turbo'],
            'speaker_session_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'progress_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'finalize_session' => ['nullable', 'boolean'],
            'sections' => ['required', 'array', 'min:1', 'max:20'],
            'sections.*.clip_index' => ['required', 'integer', 'min:1'],
            'sections.*.clip_start_ms' => ['required', 'integer', 'min:0'],
            'sections.*.clip_end_ms' => ['required', 'integer', 'min:0'],
            'sections.*.range_label' => ['required', 'string', 'max:32'],
            'sections.*.duration_ms' => ['required', 'integer', 'min:1'],
            'sections.*.prepared_name' => ['nullable', 'string', 'regex:/^chunk_\d+(?:-speech)?\.wav$/i'],
            'sections.*.source_name' => ['nullable', 'string', 'regex:/^chunk_\d+\.wav$/i'],
            'sections.*.prepared_skipped' => ['nullable', 'boolean'],
        ]);

        $sections = array_values($validated['sections']);
        $maxDurationMs = $settings->transcribeMaxBatchDurationMs() ?? 1_200_000;
        $totalDurationMs = array_sum(array_map(
            fn (array $section): int => max(0, (int) $section['duration_ms']),
            $sections,
        ));

        if ($totalDurationMs > $maxDurationMs) {
            return response()->json(['message' => 'Audio is too big.'], 422);
        }

        $userId = (int) ($validated['user_id'] ?? 1);
        $categoryName = trim((string) $validated['category_name']);
        $finalizeSession = (bool) ($validated['finalize_session'] ?? false);
        $speakerSessionId = trim((string) ($validated['speaker_session_id'] ?? $validated['upload_session_id']));
        $cleanupFiles = [];
        $batch = [];
        $rows = [];
        $diarizationProcess = null;

        try {
            foreach ($sections as $section) {
                $sourceName = trim((string) ($section['source_name'] ?? ''));
                $sourceAudio = $sourceName !== ''
                    ? $chunker->sessionAudioFile($validated['upload_session_id'], $sourceName)
                    : null;

                if ((bool) ($section['prepared_skipped'] ?? false)) {
                    if ($sourceAudio !== null) {
                        $cleanupFiles[] = $sourceAudio;
                    }

                    $rows[] = $this->skippedResponseData($section, 'upload', [
                        'prepared_duration_ms' => (int) ($sourceAudio['duration_ms'] ?? $section['duration_ms']),
                        'prepared_file_size_bytes' => (int) ($sourceAudio['size'] ?? 0),
                    ]);

                    continue;
                }

                $preparedName = trim((string) ($section['prepared_name'] ?? ''));

                if ($preparedName === '') {
                    throw new RuntimeException('Prepared audio is missing. Prepare the upload again and retry.');
                }

                $transcriptionAudio = $chunker->sessionAudioFile($validated['upload_session_id'], $preparedName);
                $cleanupFiles[] = $transcriptionAudio;

                if ($sourceAudio !== null) {
                    $cleanupFiles[] = $sourceAudio;
                }

                $batch[] = [
                    'section' => $section,
                    'audio' => $transcriptionAudio,
                ];
            }

            if ($batch !== []) {
                $online = ($validated['transcription_engine'] ?? 'online') !== 'offline';
                $diarizationProcess = $online && $speakerDiarization->canDiarize()
                    ? $this->diarizationBatchProcess($batch, $speakerSessionId)
                    : null;
                $diarizationProcess?->start();

                $transcriptions = $speechToText->transcribeBatch(array_map(
                    fn (array $item): array => [
                        'audio' => $item['audio']['path'],
                        'clip_index' => (int) $item['section']['clip_index'],
                        'clip_start_ms' => (int) $item['section']['clip_start_ms'],
                        'clip_end_ms' => (int) $item['section']['clip_end_ms'],
                    ],
                    $batch,
                ), [
                    'language_code' => $validated['language_code'] ?? 'multi',
                    ...(isset($validated['transcription_engine']) ? ['engine' => $validated['transcription_engine']] : []),
                    ...(isset($validated['whisper_model']) ? ['model' => $validated['whisper_model']] : []),
                    ...(isset($validated['progress_id']) ? ['progress_id' => $validated['progress_id']] : []),
                    'release_worker' => $finalizeSession,
                ]);
                $diarizationSegments = $this->collectDiarizationBatch($diarizationProcess);

                foreach ($batch as $batchIndex => $item) {
                    $section = $item['section'];
                    $transcription = $this->transcriptionForBatchClip($transcriptions, $section, $batchIndex);
                    $diarizationOptions = [
                        'clip_start_ms' => (int) $section['clip_start_ms'],
                        'speaker_session_id' => $speakerSessionId,
                    ];

                    if ($online && is_array($diarizationSegments)) {
                        $segments = $diarizationSegments['clip:'.((int) $section['clip_index'])]
                            ?? $diarizationSegments['queue:'.$batchIndex]
                            ?? [];
                        $transcription = $speakerDiarization->mergeSegments($item['audio']['path'], $transcription, $segments, $diarizationOptions);
                    } else {
                        $transcription = $speakerDiarization->apply($item['audio']['path'], $transcription, $diarizationOptions);
                    }

                    if ($this->isNoSpeechTranscript($transcription['text'] ?? '')) {
                        $rows[] = $this->skippedResponseData($section, 'upload', [
                            'prepared_duration_ms' => (int) $item['audio']['duration_ms'],
                            'prepared_file_size_bytes' => (int) $item['audio']['size'],
                        ]);

                        continue;
                    }

                    $rows[] = $this->storeTranscribedAudio($section, $item['audio'], $transcription, $userId, $categoryName, 'upload');
                }
            } elseif ($finalizeSession) {
                $speechToText->releaseOfflineWorker([
                    'engine' => $validated['transcription_engine'] ?? 'online',
                ]);
            }
        } catch (SpeechToTextException $exception) {
            Log::error('Uploaded audio batch transcription failed.', [
                'message' => $exception->getMessage(),
                'section_count' => count($sections),
                'language_code' => $validated['language_code'] ?? 'multi',
            ]);
            $this->stopDiarizationProcess($diarizationProcess);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (RuntimeException $exception) {
            Log::error('Uploaded audio batch could not be prepared.', [
                'message' => $exception->getMessage(),
                'section_count' => count($sections),
            ]);
            $this->stopDiarizationProcess($diarizationProcess);

            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        } catch (\Throwable $exception) {
            Log::error('Uploaded audio batch could not be processed.', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
                'section_count' => count($sections),
            ]);
            $this->stopDiarizationProcess($diarizationProcess);

            return response()->json([
                'message' => ServiceUserMessage::audioPrepareFailed(),
            ], 500);
        }

        $this->cleanupUploadedSection(
            $chunker,
            $validated['upload_session_id'],
            $finalizeSession,
            ...array_values(array_filter($cleanupFiles, 'is_array')),
        );

        if ($finalizeSession) {
            $speakerDiarization->releaseSession($speakerSessionId);
        }

        return response()->json([
            'message' => 'saved',
            'data' => $rows,
        ], 201);
    }

    private function storeUploadedSection(
        Request $request,
        SpeechToTextService $speechToText,
        AudioFileChunkerService $chunker,
        SpeechAudioFilterService $speechFilter,
        SpeakerDiarizationService $speakerDiarization,
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
            'speaker_session_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'progress_id' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'finalize_session' => ['nullable', 'boolean'],
            'prepared_name' => ['nullable', 'string', 'regex:/^chunk_\d+(?:-speech)?\.wav$/i'],
            'source_name' => ['nullable', 'string', 'regex:/^chunk_\d+\.wav$/i'],
            'prepared_skipped' => ['nullable', 'boolean'],
        ]);

        $finalizeSession = (bool) ($validated['finalize_session'] ?? false);
        $speakerSessionId = trim((string) ($validated['speaker_session_id'] ?? $validated['upload_session_id']));
        $segment = null;
        $transcriptionAudio = null;

        try {
            $userId = (int) ($validated['user_id'] ?? 1);
            $categoryName = trim((string) $validated['category_name']);
            $preparedName = trim((string) ($validated['prepared_name'] ?? ''));

            if ($preparedName !== '') {
                $transcriptionAudio = $chunker->sessionAudioFile($validated['upload_session_id'], $preparedName);
                $sourceName = trim((string) ($validated['source_name'] ?? ''));
                $segment = $sourceName !== ''
                    ? $chunker->sessionAudioFile($validated['upload_session_id'], $sourceName)
                    : $transcriptionAudio;
            } elseif ((bool) ($validated['prepared_skipped'] ?? false)) {
                $sourceName = trim((string) ($validated['source_name'] ?? ''));
                $segment = $sourceName !== ''
                    ? $chunker->sessionAudioFile($validated['upload_session_id'], $sourceName)
                    : null;

                if ($finalizeSession) {
                    $speechToText->releaseOfflineWorker([
                        'engine' => $validated['transcription_engine'] ?? 'online',
                    ]);
                    $speakerDiarization->releaseSession($speakerSessionId);
                }
                $this->cleanupUploadedSection($chunker, $validated['upload_session_id'], $finalizeSession, ...array_filter([$segment]));

                return response()->json([
                    'message' => 'skipped',
                    'data' => $this->skippedResponseData($validated, 'upload', [
                        'prepared_duration_ms' => (int) ($segment['duration_ms'] ?? $validated['duration_ms']),
                        'prepared_file_size_bytes' => (int) ($segment['size'] ?? 0),
                    ]),
                ]);
            } else {
                $segment = $chunker->extractSegment(
                    $validated['upload_session_id'],
                    (int) $validated['clip_index'],
                    (int) $validated['clip_start_ms'],
                    (int) $validated['duration_ms'],
                );
                $speechAudio = $speechFilter->prepare($segment, $this->vadContext($validated, $userId, $categoryName, 'upload'));

                if (! $speechAudio['speech_detected']) {
                    if ($finalizeSession) {
                        $speechToText->releaseOfflineWorker([
                            'engine' => $validated['transcription_engine'] ?? 'online',
                        ]);
                        $speakerDiarization->releaseSession($speakerSessionId);
                    }
                    $this->cleanupUploadedSection($chunker, $validated['upload_session_id'], $finalizeSession, $segment);

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
            }

            $transcription = $speechToText->transcribe($transcriptionAudio['path'], [
                'language_code' => $validated['language_code'] ?? 'multi',
                'clip_index' => (int) $validated['clip_index'],
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'clip_end_ms' => (int) $validated['clip_end_ms'],
                ...(isset($validated['transcription_engine']) ? ['engine' => $validated['transcription_engine']] : []),
                ...(isset($validated['whisper_model']) ? ['model' => $validated['whisper_model']] : []),
                ...(isset($validated['progress_id']) ? ['progress_id' => $validated['progress_id']] : []),
                'release_worker' => $finalizeSession,
            ]);
            $transcription = $speakerDiarization->apply($transcriptionAudio['path'], $transcription, [
                'clip_start_ms' => (int) $validated['clip_start_ms'],
                'speaker_session_id' => $speakerSessionId,
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
            if ($finalizeSession) {
                $speechToText->releaseOfflineWorker([
                    'engine' => $validated['transcription_engine'] ?? 'online',
                ]);
                $speakerDiarization->releaseSession($speakerSessionId);
            }
            $this->cleanupUploadedSection(
                $chunker,
                $validated['upload_session_id'],
                $finalizeSession,
                $segment,
                $transcriptionAudio,
            );

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

        $this->cleanupUploadedSection(
            $chunker,
            $validated['upload_session_id'],
            $finalizeSession,
            $segment,
            $transcriptionAudio,
        );
        if ($finalizeSession) {
            $speakerDiarization->releaseSession($speakerSessionId);
        }

        return response()->json([
            'message' => 'saved',
            'data' => $this->audioChunkResponseData($audioChunkId, $validated, $storedAudio, $transcription, $userId, $categoryName, 'upload'),
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

    public function releaseSpeakerSession(
        Request $request,
        SpeakerDiarizationService $speakerDiarization,
    ): JsonResponse
    {
        $validated = $request->validate([
            'speaker_session_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ]);

        $speakerDiarization->releaseSession((string) $validated['speaker_session_id']);

        return response()->json(['message' => 'released']);
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

    private function cleanupUploadedSection(
        AudioFileChunkerService $chunker,
        string $sessionId,
        bool $finalizeSession,
        array ...$audioFiles,
    ): void {
        if ($finalizeSession) {
            $chunker->cleanupSession($sessionId);

            return;
        }

        $chunker->cleanupProcessedFiles(...$audioFiles);
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

    private function transcriptionForBatchClip(array $transcriptions, array $section, int $batchIndex): array
    {
        $clipIndex = (int) $section['clip_index'];

        foreach ($transcriptions as $transcription) {
            if (! is_array($transcription)) {
                continue;
            }

            if (isset($transcription['clip_index']) && (int) $transcription['clip_index'] === $clipIndex) {
                return [
                    'text' => (string) ($transcription['text'] ?? ''),
                    'timestamps' => is_array($transcription['timestamps'] ?? null) ? $transcription['timestamps'] : [],
                ];
            }

            if (isset($transcription['queue_index']) && (int) $transcription['queue_index'] === $batchIndex) {
                return [
                    'text' => (string) ($transcription['text'] ?? ''),
                    'timestamps' => is_array($transcription['timestamps'] ?? null) ? $transcription['timestamps'] : [],
                ];
            }
        }

        $fallback = $transcriptions[$batchIndex] ?? null;

        if (is_array($fallback)) {
            return [
                'text' => (string) ($fallback['text'] ?? ''),
                'timestamps' => is_array($fallback['timestamps'] ?? null) ? $fallback['timestamps'] : [],
            ];
        }

        return ['text' => '', 'timestamps' => []];
    }

    private function storeTranscribedAudio(
        array $validated,
        array $storedAudio,
        array $transcription,
        int $userId,
        string $categoryName,
        string $sourceType,
    ): array {
        $contents = file_get_contents($storedAudio['path']);

        if ($contents === false) {
            throw new RuntimeException(ServiceUserMessage::audioReadFailed());
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

        return $this->audioChunkResponseData($audioChunkId, $validated, $storedAudio, $transcription, $userId, $categoryName, $sourceType);
    }

    private function audioChunkResponseData(
        int $audioChunkId,
        array $validated,
        array $storedAudio,
        array $transcription,
        int $userId,
        string $categoryName,
        string $sourceType,
    ): array {
        return [
            'id' => $audioChunkId,
            'user_id' => $userId,
            'category_name' => $categoryName,
            'source_type' => $sourceType,
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
        ];
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
