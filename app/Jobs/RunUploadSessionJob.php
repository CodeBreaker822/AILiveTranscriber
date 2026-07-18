<?php

namespace App\Jobs;

use App\Services\AudioChunk\UploadSessionDirector;
use App\Services\BackgroundJobs\BackgroundJobStore;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class RunUploadSessionJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public int $timeout = 0;

    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public array $payload,
    ) {
        $this->onQueue('audio');
    }

    public function handle(
        BackgroundJobStore $jobs,
        UploadSessionDirector $director,
    ): void {
        if ($jobs->cancelled($this->jobId)) {
            return;
        }

        $jobs->markRunning($this->jobId);

        $onProgress = function (int $sectionIndex, int $sectionTotal, string $phase, int $percent) use ($jobs): void {
            $jobs->markProgress($this->jobId, [
                'phase' => $phase,
                'percent' => $percent,
                'section_index' => $sectionIndex,
                'section_total' => $sectionTotal,
            ]);
        };

        try {
            $result = $director->run(
                (int) ($this->payload['user_id'] ?? 1),
                trim((string) ($this->payload['category_name'] ?? '')),
                trim((string) ($this->payload['upload_session_id'] ?? '')),
                [
                    'engine' => (string) ($this->payload['transcription_engine'] ?? 'online'),
                    'use_vad' => (bool) ($this->payload['use_vad'] ?? true),
                    'use_diarization' => (bool) ($this->payload['use_diarization'] ?? false),
                    'language_code' => (string) ($this->payload['language_code'] ?? 'multi'),
                    'whisper_model' => (string) ($this->payload['whisper_model'] ?? 'turbo'),
                    'duration_ms' => (int) ($this->payload['duration_ms'] ?? 0),
                    'chunk_seconds' => (int) ($this->payload['chunk_seconds'] ?? 60),
                ],
                $onProgress,
            );

            if ($jobs->cancelled($this->jobId)) {
                return;
            }

            $jobs->markCompleted($this->jobId, $result, 200);
        } catch (Throwable $exception) {
            if ($jobs->cancelled($this->jobId)) {
                return;
            }

            $status = (int) $exception->getCode();
            $jobs->markFailed(
                $this->jobId,
                $exception->getMessage() ?: 'Session processing failed.',
                $status >= 400 && $status <= 599 ? $status : 500,
            );
        }
    }
}
