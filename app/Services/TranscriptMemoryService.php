<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TranscriptMemoryService
{
    public function snapshot(): array
    {
        $raw = $this->rawTranscriptSnapshot();
        $cleaned = $this->cleanedTranscriptSnapshot();
        $totalBytes = $raw['bytes'] + $cleaned['bytes'];

        return [
            'total' => [
                'bytes' => $totalBytes,
                'formatted_size' => $this->formatBytes($totalBytes),
            ],
            'raw' => array_merge($raw, [
                'formatted_size' => $this->formatBytes($raw['bytes']),
            ]),
            'cleaned' => array_merge($cleaned, [
                'formatted_size' => $this->formatBytes($cleaned['bytes']),
            ]),
        ];
    }

    public function purgeTranscriptText(): array
    {
        $before = $this->snapshot();

        DB::transaction(function (): void {
            if (Schema::hasTable('clean_transcript_chunks')) {
                DB::table('clean_transcript_chunks')->delete();
            }

            if (Schema::hasTable('audio_chunks')) {
                DB::table('audio_chunks')->update([
                    'translated_text' => null,
                    'transcription_timestamps' => null,
                    'updated_at' => now(),
                ]);
            }
        });

        return $before['total'];
    }

    private function rawTranscriptSnapshot(): array
    {
        if (! Schema::hasTable('audio_chunks')) {
            return [
                'bytes' => 0,
                'records' => 0,
            ];
        }

        $row = DB::table('audio_chunks')
            ->selectRaw("
                COUNT(CASE WHEN translated_text IS NOT NULL OR transcription_timestamps IS NOT NULL THEN 1 END) as records,
                COALESCE(SUM(
                    LENGTH(COALESCE(CAST(translated_text AS CHAR), '')) +
                    LENGTH(COALESCE(CAST(transcription_timestamps AS CHAR), ''))
                ), 0) as bytes
            ")
            ->first();

        return [
            'bytes' => (int) ($row->bytes ?? 0),
            'records' => (int) ($row->records ?? 0),
        ];
    }

    private function cleanedTranscriptSnapshot(): array
    {
        if (! Schema::hasTable('clean_transcript_chunks')) {
            return [
                'bytes' => 0,
                'records' => 0,
            ];
        }

        $row = DB::table('clean_transcript_chunks')
            ->selectRaw("
                COUNT(*) as records,
                COALESCE(SUM(
                    LENGTH(COALESCE(CAST(raw_text AS CHAR), '')) +
                    LENGTH(COALESCE(CAST(clean_text AS CHAR), '')) +
                    LENGTH(COALESCE(CAST(clean_timestamps AS CHAR), ''))
                ), 0) as bytes
            ")
            ->first();

        return [
            'bytes' => (int) ($row->bytes ?? 0),
            'records' => (int) ($row->records ?? 0),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024 * 1024), 2).' GB';
        }

        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return number_format($bytes).' B';
    }
}
