<?php

namespace App\Services\AudioChunk;

use App\Models\AudioChunk;
use App\Services\Transcripts\TranscriptDerivationService;

class AudioChunkRowPresenter
{
    public function __construct(
        private readonly AudioChunkPayloadService $payloads,
        private readonly TranscriptDerivationService $derivation,
    ) {}

    public function row(AudioChunk $row): array
    {
        $translatedText = $row->translated_text ?? null;
        $timestamps = is_array($row->transcription_timestamps)
            ? $row->transcription_timestamps
            : [];

        $parts = $this->derivation->exportParts($translatedText, $timestamps);

        return [
            'id' => $row->id,
            'clip_index' => (int) $row->clip_index,
            'clip_start_ms' => (int) $row->clip_start_ms,
            'clip_end_ms' => (int) $row->clip_end_ms,
            'range_label' => $row->range_label,
            'duration_ms' => (int) $row->duration_ms,
            'category_name' => $row->category_name ?: 'General',
            'source_type' => $this->payloads->sourceType($row->original_name),
            'status' => $row->status,
            'play_url' => route('audio-chunks.audio', ['audioChunk' => $row->id]),
            'delete_url' => route('audio-chunks.destroy', ['audioChunk' => $row->id]),
            'translated_text' => $translatedText,
            'transcription_timestamps' => $timestamps,
            'is_useful' => $this->derivation->isUsefulText($translatedText),
            'display_text' => $parts['transcriptText'],
            'speaker_turns' => $parts['turns'],
            'speaker_labels' => $parts['speakerLabels'],
        ];
    }
}
