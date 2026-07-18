<?php

namespace App\Services\AudioChunk;

use App\Services\Audio\AudioSectionPlanner;
use App\Services\Audio\UploadedAudioBatchPreparationService;
use App\Services\Audio\UploadedAudioSectionPreparationService;
use App\Services\AudioChunk\AudioChunkIngestionService;
use App\Services\AudioChunk\UploadedDiarizationService;
use Closure;
use RuntimeException;

/**
 * Server-side pipeline driver for an uploaded audio session. The browser used
 * to sequence prepare -> transcribe -> diarize itself (with concurrency, retry,
 * and a diarization monitor loop); that orchestration now lives here. The
 * frontend only starts this job and polls its progress.
 */
final class UploadSessionDirector
{
    /**
     * @param  Closure(int, int, string, int): void  $onProgress  ($sectionIndex, $sectionTotal, $phase, $percent)
     */
    public function __construct(
        private readonly AudioSectionPlanner $planner,
        private readonly UploadedAudioBatchPreparationService $batchPreparer,
        private readonly UploadedAudioSectionPreparationService $sectionPreparer,
        private readonly AudioChunkIngestionService $ingestion,
        private readonly UploadedDiarizationService $diarization,
        private readonly Closure $onProgress,
    ) {}

    /**
     * @param  array{engine?: string, use_vad?: bool, use_diarization?: bool, language_code?: string, whisper_model?: string, duration_ms?: int, chunk_seconds?: int}  $options
     */
    public function run(int $userId, string $categoryName, string $sessionId, array $options = [], ?Closure $onProgress = null): array
    {
        if ($onProgress !== null) {
            $this->onProgress = $onProgress;
        }
        $engine = ($options['engine'] ?? 'online') === 'offline' ? 'offline' : 'online';
        $useVad = (bool) ($options['use_vad'] ?? true);
        $useDiarization = (bool) ($options['use_diarization'] ?? false);
        $languageCode = trim((string) ($options['language_code'] ?? 'multi')) ?: 'multi';
        $whisperModel = trim((string) ($options['whisper_model'] ?? 'turbo')) ?: 'turbo';
        $chunkSeconds = max(1, (int) ($options['chunk_seconds'] ?? 60));
        $durationMs = max(1, (int) ($options['duration_ms'] ?? 0));

        if ($durationMs <= 0) {
            throw new RuntimeException('The upload session duration is not available.');
        }

        $sections = array_map(
            fn (array $section): array => [
                'clip_index' => (int) ($section['index'] ?? $section['clip_index'] ?? 0),
                'clip_start_ms' => (int) ($section['start_ms'] ?? 0),
                'clip_end_ms' => (int) ($section['end_ms'] ?? 0),
                'range_label' => (string) ($section['range_label'] ?? ''),
                'duration_ms' => (int) ($section['duration_ms'] ?? 1),
            ],
            $this->planner->buildSections($durationMs, $chunkSeconds),
        );
        $sectionTotal = count($sections);

        if ($sectionTotal === 0) {
            throw new RuntimeException('No transcript sections could be planned for this audio.');
        }

        $this->progress(0, $sectionTotal, 'prepare', 0);

        $prepared = $this->prepareSections($userId, $categoryName, $sessionId, $useVad, $sections, $engine, $sectionTotal);
        $this->progress(0, $sectionTotal, 'transcribe', 0);

        $transcribed = $engine === 'offline'
            ? $this->transcribeOffline($userId, $categoryName, $sessionId, $useVad, $useDiarization, $whisperModel, $prepared, $sectionTotal)
            : $this->transcribeOnline($userId, $categoryName, $sessionId, $useVad, $useDiarization, $languageCode, $prepared, $sectionTotal);

        if ($useDiarization && $engine === 'online') {
            $this->diarize($userId, $categoryName, $sessionId, $transcribed);
        }

        $this->progress($sectionTotal, $sectionTotal, 'complete', 100);

        return [
            'message' => 'processed',
            'session_id' => $sessionId,
            'category_name' => $categoryName,
            'sections' => $transcribed,
            'count' => count($transcribed),
        ];
    }

    /**
     * @param  array<int, array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int}>  $sections
     * @return array<int, array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int, prepared_name: string, source_name: string, prepared_skipped: bool}>
     */
    private function prepareSections(int $userId, string $categoryName, string $sessionId, bool $useVad, array $sections, string $engine, int $sectionTotal): array
    {
        $base = [
            'upload_session_id' => $sessionId,
            'user_id' => $userId,
            'category_name' => $categoryName,
            'use_vad' => $useVad,
        ];

        if ($engine === 'offline') {
            return array_map(function (array $section, int $index) use ($base, $sectionTotal): array {
                $prepared = $this->sectionPreparer->prepare([...$base, ...$section]);
                $this->progress($index + 1, $sectionTotal, 'prepare', (int) (($index + 1) / $sectionTotal * 100));

                return $this->mergePrepared($section, $prepared);
            }, $sections, array_keys($sections));
        }

        $batch = $this->batchPreparer->prepare([
            ...$base,
            'concurrency' => 1,
            'sections' => $sections,
        ]);

        $rows = collect($batch['data'] ?? [])->keyBy('clip_index');

        return array_map(function (array $section) use ($rows): array {
            $prepared = $rows->get($section['clip_index'], $section);

            return $this->mergePrepared($section, $prepared);
        }, $sections);
    }

    /**
     * @param  array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int}  $section
     * @param  array<string, mixed>  $prepared
     * @return array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int, prepared_name: string, source_name: string, prepared_skipped: bool}
     */
    private function mergePrepared(array $section, array $prepared): array
    {
        $skipped = (bool) ($prepared['skipped'] ?? $prepared['prepared_skipped'] ?? false);

        return [
            ...$section,
            'audio_chunk_id' => (int) ($prepared['audio_chunk_id'] ?? 0),
            'prepared_name' => (string) ($prepared['prepared_name'] ?? ''),
            'source_name' => (string) ($prepared['source_name'] ?? ''),
            'prepared_skipped' => $skipped,
        ];
    }

    /**
     * @param  array<int, array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int, prepared_name: string, source_name: string, prepared_skipped: bool}>  $sections
     * @return array<int, array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int}>
     */
    private function transcribeOnline(int $userId, string $categoryName, string $sessionId, bool $useVad, bool $useDiarization, string $languageCode, array $sections, int $sectionTotal): array
    {
        $speakerSessionId = $useDiarization ? $sessionId : '';
        $result = $this->ingestion->storeUploadedBatch([
            'upload_session_id' => $sessionId,
            'user_id' => $userId,
            'category_name' => $categoryName,
            'language_code' => $languageCode,
            'transcription_engine' => 'online',
            'speaker_session_id' => $speakerSessionId,
            'use_vad' => $useVad,
            'use_diarization' => $useDiarization,
            'finalize_session' => true,
            'sections' => array_map(function (array $section, int $index) use ($sectionTotal): array {
                $row = [
                    'clip_index' => $section['clip_index'],
                    'clip_start_ms' => $section['clip_start_ms'],
                    'clip_end_ms' => $section['clip_end_ms'],
                    'range_label' => $section['range_label'],
                    'duration_ms' => $section['duration_ms'],
                    'audio_chunk_id' => $section['audio_chunk_id'] > 0 ? $section['audio_chunk_id'] : null,
                    'prepared_name' => $section['prepared_name'] ?: null,
                    'source_name' => $section['source_name'] ?: null,
                    'prepared_skipped' => $section['prepared_skipped'] ? 1 : 0,
                ];

                $this->progress($index + 1, $sectionTotal, 'transcribe', (int) (($index + 1) / $sectionTotal * 100));

                return $row;
            }, $sections, array_keys($sections)),
        ]);

        return $this->ingestedSections($result, $sections);
    }

    /**
     * @param  array<int, array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int, prepared_name: string, source_name: string, prepared_skipped: bool}>  $sections
     * @return array<int, array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int}>
     */
    private function transcribeOffline(int $userId, string $categoryName, string $sessionId, bool $useVad, bool $useDiarization, string $whisperModel, array $sections, int $sectionTotal): array
    {
        $speakerSessionId = $useDiarization ? $sessionId : '';
        $out = [];

        foreach ($sections as $index => $section) {
            if ($section['prepared_skipped']) {
                $out[] = $this->sectionResult($section, null);
                continue;
            }

            $result = $this->ingestion->storeUploadedSection([
                'upload_session_id' => $sessionId,
                'user_id' => $userId,
                'category_name' => $categoryName,
                'clip_index' => $section['clip_index'],
                'clip_start_ms' => $section['clip_start_ms'],
                'clip_end_ms' => $section['clip_end_ms'],
                'range_label' => $section['range_label'],
                'duration_ms' => $section['duration_ms'],
                'language_code' => 'auto',
                'transcription_engine' => 'offline',
                'whisper_model' => $whisperModel,
                'speaker_session_id' => $speakerSessionId,
                'use_vad' => $useVad,
                'use_diarization' => $useDiarization,
                'source_name' => $section['source_name'] ?: null,
                'prepared_name' => $section['prepared_name'] ?: null,
                'audio_chunk_id' => $section['audio_chunk_id'] > 0 ? $section['audio_chunk_id'] : null,
                'prepared_skipped' => 0,
                'finalize_session' => $index === count($sections) - 1,
            ]);

            $out[] = $this->sectionResult($section, $result);
            $this->progress($index + 1, $sectionTotal, 'transcribe', (int) (($index + 1) / $sectionTotal * 100));
        }

        return $out;
    }

    /**
     * @param  array<int, array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int}>  $sections
     * @return array<int, array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int}>
     */
    private function diarize(int $userId, string $categoryName, string $sessionId, array $sections): array
    {
        $this->progress(0, count($sections), 'diarize', 0);

        $diarizable = array_filter($sections, fn (array $section): bool => ($section['audio_chunk_id'] ?? 0) > 0);

        if ($diarizable === []) {
            return $sections;
        }

        $this->diarization->queuePreparedSections(
            $sessionId,
            $sessionId,
            $userId,
            $categoryName,
            array_values($diarizable),
        );

        $this->progress(count($sections), count($sections), 'diarize', 100);

        return $sections;
    }

    /**
     * @param  array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int, prepared_name?: string, source_name?: string, prepared_skipped?: bool}  $section
     * @param  AudioChunkIngestionResult|null  $result
     * @return array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int}
     */
    private function sectionResult(array $section, ?AudioChunkIngestionResult $result): array
    {
        $audioChunkId = $section['audio_chunk_id'] ?? 0;

        if ($result !== null) {
            $payload = $result->toResponsePayload();
            $audioChunkId = (int) ($payload['data']['id'] ?? $audioChunkId);
        }

        return [
            'clip_index' => $section['clip_index'],
            'clip_start_ms' => $section['clip_start_ms'],
            'clip_end_ms' => $section['clip_end_ms'],
            'range_label' => $section['range_label'],
            'duration_ms' => $section['duration_ms'],
            'audio_chunk_id' => $audioChunkId,
        ];
    }

    /**
     * @param  AudioChunkIngestionResult  $result
     * @param  array<int, array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int}>  $sections
     * @return array<int, array{clip_index: int, clip_start_ms: int, clip_end_ms: int, range_label: string, duration_ms: int, audio_chunk_id: int}>
     */
    private function ingestedSections(AudioChunkIngestionResult $result, array $sections): array
    {
        $payload = $result->toResponsePayload();
        $rows = collect($payload['data'] ?? [])->keyBy('clip_index');

        return array_map(function (array $section) use ($rows): array {
            $row = $rows->get($section['clip_index'], $section);

            return $this->sectionResult($section, null)
                + ['audio_chunk_id' => (int) ($row['id'] ?? $section['audio_chunk_id'] ?? 0)];
        }, $sections);
    }

    private function progress(int $sectionIndex, int $sectionTotal, string $phase, int $percent): void
    {
        ($this->onProgress)($sectionIndex, $sectionTotal, $phase, $percent);
    }
}
