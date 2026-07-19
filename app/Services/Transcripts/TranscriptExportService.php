<?php

namespace App\Services\Transcripts;

use App\Models\AudioChunk;
use App\Models\CleanTranscriptChunk;
use App\Services\AudioChunk\AudioChunkRowPresenter;
use App\Services\Support\ServiceUserMessage;
use RuntimeException;

/**
 * Builds transcript export files server-side. The browser used to assemble
 * the txt/xls/doc content (and re-derive speaker turns); that work now lives
 * here so the frontend only triggers the download.
 */
final class TranscriptExportService
{
    private const FORMATS = ['txt', 'excel', 'word'];

    public function __construct(
        private readonly AudioChunkRowPresenter $rawRows,
        private readonly CleanTranscriptChunkPresenter $cleanRows,
    ) {}

    /**
     * @return array{filename: string, mime_type: string, content: string}
     */
    public function build(int $userId, string $categoryName, string $mode, string $format): array
    {
        $mode = $mode === 'clean' ? 'clean' : 'raw';
        $format = strtolower(trim($format));

        if (! in_array($format, self::FORMATS, true)) {
            $format = 'txt';
        }

        $rows = $this->rows($userId, $categoryName, $mode);

        if ($rows === []) {
            throw new RuntimeException($mode === 'clean'
                ? ServiceUserMessage::noCleanedTranscript($categoryName)
                : ServiceUserMessage::noRawTranscript($categoryName));
        }

        $title = trim($categoryName) ?: 'Transcription';
        $variantLabel = $mode === 'clean' ? 'Cleaned' : 'Raw';
        $base = $this->slugify($title).'-'.$mode.'-transcription';

        if ($format === 'excel') {
            return [
                'filename' => $base.'.xls',
                'mime_type' => 'application/vnd.ms-excel;charset=utf-8',
                'content' => $this->toSpreadsheetHtml($title, $variantLabel, $rows),
            ];
        }

        if ($format === 'word') {
            return [
                'filename' => $base.'.doc',
                'mime_type' => 'application/msword;charset=utf-8',
                'content' => $this->toWordHtml($title, $variantLabel, $rows),
            ];
        }

        return [
            'filename' => $base.'.txt',
            'mime_type' => 'text/plain;charset=utf-8',
            'content' => $this->toText($rows),
        ];
    }

    /**
     * @return array<int, array{range_label: string, display_text: string, speaker_labels: array<int, string>, speaker_turns: array<int, array{speakerId: string, text: string, speakerLabel?: string}>}>
     */
    private function rows(int $userId, string $categoryName, string $mode): array
    {
        if ($mode === 'clean') {
            return CleanTranscriptChunk::query()
                ->where('user_id', $userId)
                ->where('category_name', $categoryName)
                ->orderBy('clip_start_ms')
                ->get()
                ->map(fn (CleanTranscriptChunk $row): array => $this->cleanRows->row($row))
                ->filter(fn (array $row): bool => ($row['is_useful'] ?? false) === true)
                ->all();
        }

        return AudioChunk::query()
            ->where('user_id', $userId)
            ->where('category_name', $categoryName)
            ->orderBy('clip_start_ms')
            ->get()
            ->map(fn (AudioChunk $row): array => $this->rawRows->row($row))
            ->filter(fn (array $row): bool => ($row['is_useful'] ?? false) === true)
            ->all();
    }

    /**
     * @param  array<int, array{range_label: string, display_text: string, speaker_labels: array<int, string>}>  $rows
     */
    private function toText(array $rows): string
    {
        return implode("\n\n", array_map(
            fn (array $row): string => trim(implode("\n", array_filter([
                $row['range_label'] ?? '',
                $row['display_text'] ?? '',
            ], fn ($value) => $value !== null && $value !== '')), "\n"),
            $rows,
        ));
    }

    /**
     * @param  array<int, array{range_label: string, display_text: string, speaker_labels: array<int, string>}>  $rows
     */
    private function toSpreadsheetHtml(string $title, string $variantLabel, array $rows): string
    {
        $documentTitle = $this->documentTitle($title, $variantLabel);
        $brandName = $this->brandName();
        $body = implode('', array_map(function (array $row, int $index): string {
            $speakers = implode(', ', $row['speaker_labels'] ?? []) ?: 'Transcript';

            return <<<HTML
                <tr>
                    <td>{$index}</td>
                    <td class="range">{$this->e($row['range_label'] ?? '')}</td>
                    <td class="speaker">{$this->e($speakers)}</td>
                    <td class="transcript">{$this->e($row['display_text'] ?? '')}</td>
                </tr>
            HTML;
        }, $rows, array_keys($rows)));

        return <<<HTML
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                body { font-family: Calibri, Arial, sans-serif; color: #0f172a; }
                h1 { color: #0891b2; font-size: 22px; margin: 0 0 4px; }
                .meta { color: #64748b; font-size: 12px; margin: 0 0 14px; }
                table { border-collapse: collapse; width: 100%; }
                th { background: #0f172a; color: #ffffff; font-weight: 700; padding: 9px; text-align: left; border: 1px solid #1e293b; }
                td { padding: 8px; vertical-align: top; border: 1px solid #cbd5e1; }
                tr:nth-child(even) td { background: #f8fafc; }
                .range { color: #0891b2; font-weight: 700; white-space: nowrap; }
                .speaker { color: #0f766e; font-weight: 700; }
                .transcript { white-space: pre-wrap; line-height: 1.35; }
            </style>
        </head>
        <body>
            <h1>{$this->e($documentTitle)}</h1>
            <p class="meta">Generated by {$this->e($brandName)}</p>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Time Range</th>
                        <th>Speakers</th>
                        <th>Transcript</th>
                    </tr>
                </thead>
                <tbody>
                    {$body}
                </tbody>
            </table>
        </body>
        </html>
        HTML;
    }

    /**
     * @param  array<int, array{range_label: string, display_text: string, speaker_labels: array<int, string>, speaker_turns: array<int, array{speakerId: string, text: string, speakerLabel?: string}>}>  $rows
     */
    private function toWordHtml(string $title, string $variantLabel, array $rows): string
    {
        $documentTitle = $this->documentTitle($title, $variantLabel);
        $brandName = $this->brandName();
        $body = implode('', array_map(function (array $row): string {
            $range = $row['range_label'] ?? 'Transcript';
            $sections = $row['speaker_turns'] ?? [];

            $inner = $sections !== []
                ? implode('', array_map(function (array $turn): string {
                    $label = $turn['speakerLabel'] ?? $turn['speakerId'] ?? 'Speaker';

                    return "<p><span class=\"speaker\">{$this->e($label)}:</span> {$this->e($turn['text'] ?? '')}</p>";
                }, $sections))
                : "<p>{$this->e($row['display_text'] ?? '')}</p>";

            return <<<HTML
                <div class="section">
                    <div class="range">{$this->e($range)}</div>
                    {$inner}
                </div>
            HTML;
        }, $rows));

        return <<<HTML
        <!doctype html>
        <html>
        <head>
            <meta charset="utf-8">
            <style>
                body { font-family: Calibri, Arial, sans-serif; color: #111827; line-height: 1.5; }
                .page { max-width: 760px; margin: 0 auto; }
                h1 { margin: 0 0 4px; color: #0f172a; font-size: 26px; }
                .subtitle { margin: 0 0 20px; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 1.8px; }
                .section { border-top: 2px solid #bae6fd; padding: 14px 0 10px; }
                .range { display: inline-block; margin-bottom: 8px; padding: 4px 8px; border-radius: 999px; background: #ecfeff; color: #0e7490; font-weight: 700; }
                p { margin: 0 0 8px; }
                .speaker { color: #0f766e; font-weight: 700; }
            </style>
        </head>
        <body>
            <div class="page">
                <h1>{$this->e($documentTitle)}</h1>
                <p class="subtitle">Generated by {$this->e($brandName)}</p>
                {$body}
            </div>
        </body>
        </html>
        HTML;
    }

    private function documentTitle(string $title, string $variantLabel): string
    {
        return $title.' - '.$variantLabel.' Transcript';
    }

    private function brandName(): string
    {
        return trim((string) config('app.brand_name', config('app.name', 'Transcriber'))) ?: 'Transcriber';
    }

    private function e(?string $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function slugify(string $value, string $fallback = 'transcription'): string
    {
        $slug = strtolower(trim($value));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? $fallback;
        $slug = trim($slug, '-') ?: $fallback;

        return $slug;
    }
}
