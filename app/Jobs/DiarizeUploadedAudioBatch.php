<?php

namespace App\Jobs;

use App\Services\SpeakerDiarizationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Throwable;

class DiarizeUploadedAudioBatch implements ShouldQueue
{
    use Queueable;

    public int $timeout = 0;

    /**
     * @param  array<int, int>  $audioChunkIds
     */
    public function __construct(
        public array $audioChunkIds,
        public string $speakerSessionId,
        public bool $finalizeSession = false,
    ) {
        $this->audioChunkIds = array_values(array_unique(array_map('intval', $audioChunkIds)));
    }

    public function handle(SpeakerDiarizationService $speakerDiarization): void
    {
        foreach ($this->audioChunkIds as $audioChunkId) {
            $row = DB::table('audio_chunks')->where('id', $audioChunkId)->first();

            if (! $row || ! is_string($row->audio_blob) || $row->audio_blob === '') {
                Log::warning('Queued speaker diarization skipped a missing audio chunk.', [
                    'audio_chunk_id' => $audioChunkId,
                    'speaker_session_id' => $this->speakerSessionId,
                ]);

                continue;
            }

            $directory = storage_path('app/private/speaker-diarization-queue');
            File::ensureDirectoryExists($directory);
            $audioPath = $directory.DIRECTORY_SEPARATOR.'chunk-'.$audioChunkId.'-'.bin2hex(random_bytes(6)).'.wav';

            try {
                if (file_put_contents($audioPath, $row->audio_blob) === false) {
                    throw new \RuntimeException('Queued audio could not be written for speaker diarization.');
                }

                $transcription = [
                    'text' => (string) ($row->translated_text ?? ''),
                    'timestamps' => is_string($row->transcription_timestamps)
                        ? (json_decode($row->transcription_timestamps, true) ?: [])
                        : [],
                ];
                $merged = $speakerDiarization->apply($audioPath, $transcription, [
                    'clip_start_ms' => (int) $row->clip_start_ms,
                    'speaker_session_id' => $this->speakerSessionId,
                ]);

                DB::table('audio_chunks')
                    ->where('id', $audioChunkId)
                    ->where('clip_index', (int) $row->clip_index)
                    ->update([
                        'translated_text' => (string) ($merged['text'] ?? $transcription['text']),
                        'transcription_timestamps' => json_encode($merged['timestamps'] ?? $transcription['timestamps']),
                        'status' => 'transcribed',
                        'updated_at' => now(),
                    ]);
            } catch (Throwable $exception) {
                Log::warning('Queued speaker diarization failed.', [
                    'error' => $exception->getMessage(),
                    'audio_chunk_id' => $audioChunkId,
                    'speaker_session_id' => $this->speakerSessionId,
                ]);

                DB::table('audio_chunks')->where('id', $audioChunkId)->update([
                    'status' => 'transcribed',
                    'updated_at' => now(),
                ]);
            } finally {
                @unlink($audioPath);
            }
        }

        if ($this->finalizeSession) {
            $speakerDiarization->releaseSession($this->speakerSessionId);
        }
    }
}
