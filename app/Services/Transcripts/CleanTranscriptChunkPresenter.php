<?php

namespace App\Services\Transcripts;

use App\Models\CleanTranscriptChunk;

class CleanTranscriptChunkPresenter
{
    public function __construct(private readonly TranscriptDerivationService $derivation) {}

    public function row(CleanTranscriptChunk $row): array
    {
        $cleanText = $row->clean_text ?? '';
        $timestamps = is_array($row->clean_timestamps) ? $row->clean_timestamps : [];

        $parts = $this->derivation->exportParts($cleanText, $timestamps);

        return [
            'audio_chunk_id' => (int) $row->audio_chunk_id,
            'clip_index' => (int) $row->clip_index,
            'clip_start_ms' => (int) $row->clip_start_ms,
            'clip_end_ms' => (int) $row->clip_end_ms,
            'range_label' => $row->range_label,
            'clean_text' => $cleanText,
            'clean_timestamps' => $timestamps,
            'provider' => $row->provider,
            'model' => $row->model,
            'is_useful' => $this->derivation->isUsefulText($cleanText),
            'display_text' => $parts['transcriptText'],
            'speaker_turns' => $parts['turns'],
            'speaker_labels' => $parts['speakerLabels'],
        ];
    }
}
