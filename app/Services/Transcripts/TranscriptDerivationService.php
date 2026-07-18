<?php

namespace App\Services\Transcripts;

/**
 * Server-side mirror of the transcript shaping the browser used to do in
 * resources/js/shared/transcripts.js. Derivations that are data (not view)
 * belong here so the frontend only renders what it is given.
 */
final class TranscriptDerivationService
{
    private const NO_SPEECH_VALUES = ['', 'no speech detected', 'no speech detected.'];

    public function isUsefulText(?string $text): bool
    {
        $normalized = strtolower(trim((string) $text));

        return ! in_array($normalized, self::NO_SPEECH_VALUES, true);
    }

    /**
     * @param  array<string, mixed>|object|null  $item
     */
    public function itemHasUsefulTranscript(mixed $item): bool
    {
        $text = is_array($item) ? ($item['translated_text'] ?? $item['translated_text'] ?? null) : null;

        if ($text === null && is_object($item)) {
            $text = $item->translated_text ?? null;
        }

        return $this->isUsefulText((string) $text);
    }

    public function speakerLabel(?string $speakerId): string
    {
        $speakerId = (string) ($speakerId ?? '');
        $match = preg_match('/(\d+)$/', $speakerId, $matches) === 1 ? $matches[1] : null;

        return $match !== null ? 'Speaker '.max(1, (int) $match) : 'Speaker';
    }

    private function appendTranscriptPart(?string $current, string $part): ?string
    {
        $part = trim($part);

        if ($current === null || $current === '' || $part === '') {
            return $current === null ? ($part === '' ? null : $part) : $current.$part;
        }

        if (preg_match('/^[.,!?;:%)\]}]/u', $part) === 1 || preg_match('/[(\[{]$/u', $current) === 1) {
            return $current.$part;
        }

        return $current.' '.$part;
    }

    /**
     * @param  array<int, array<string, mixed>>  $timestamps
     * @return array<int, array{speakerId: string, text: string}>
     */
    public function speakerTurnsFromTimestamps(array $timestamps): array
    {
        $turns = [];

        foreach ($timestamps as $entry) {
            $part = trim((string) ($entry['text'] ?? ''));
            $speakerId = trim((string) ($entry['speaker_id'] ?? $entry['speakerId'] ?? ''));

            if ($part === '' || $speakerId === '') {
                continue;
            }

            $previous = end($turns);

            if ($previous !== false && $previous['speakerId'] === $speakerId) {
                $turns[count($turns) - 1]['text'] = (string) $this->appendTranscriptPart($previous['text'], $part);
                continue;
            }

            $turns[] = ['speakerId' => $speakerId, 'text' => $part];
        }

        return $turns;
    }

    /**
     * @param  array<int, array<string, mixed>>  $timestamps
     */
    public function textWithSpeakerTurns(?string $text, array $timestamps): string
    {
        $turns = $this->speakerTurnsFromTimestamps($timestamps);

        if ($turns === []) {
            return trim((string) $text);
        }

        return implode("\n", array_map(
            fn (array $turn): string => $this->speakerLabel($turn['speakerId']).': '.$turn['text'],
            $turns,
        ));
    }

    /**
     * @param  array<int, array<string, mixed>>  $timestamps
     * @return array{speakerLabels: array<int, string>, turns: array<int, array{speakerId: string, text: string}>}
     */
    public function exportParts(?string $text, array $timestamps): array
    {
        $turns = $this->speakerTurnsFromTimestamps($timestamps);
        $speakerLabels = array_values(array_unique(array_map(
            fn (array $turn): string => $this->speakerLabel($turn['speakerId']),
            $turns,
        )));

        return [
            'speakerLabels' => $speakerLabels,
            'turns' => $turns,
            'transcriptText' => $this->textWithSpeakerTurns($text, $timestamps),
        ];
    }
}
