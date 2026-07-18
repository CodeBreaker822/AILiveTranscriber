// View-only rendering of transcript rows. Speaker-turn reconstruction and
// "usefulness" filtering now happen server-side (AudioChunkRowPresenter /
// CleanTranscriptChunkPresenter), so this module only formats what the
// backend already derived: display_text, speaker_turns, speaker_labels.

import { escapeHtml } from './dom.js';

export const transcriptDisplayText = (item) => String(
    item?.display_text
    || item?.translatedText
    || item?.translated_text
    || item?.cleanText
    || item?.clean_text
    || '',
).trim();

export const transcriptSpeakerTurns = (item) => Array.isArray(item?.speaker_turns)
    ? item.speaker_turns
    : [];

const speakerLabelOf = (turn) => String(turn?.speakerLabel || turn?.speakerId || 'Speaker');

// View-only: formats a raw speaker id (e.g. "speaker_2") as "Speaker N".
export const speakerLabel = (speakerId) => {
    const match = String(speakerId || '').match(/(\d+)$/);

    return match ? `Speaker ${Math.max(1, Number(match[1]))}` : 'Speaker';
};

export const renderTranscriptText = (item) => {
    const turns = transcriptSpeakerTurns(item);
    const text = transcriptDisplayText(item);

    if (!turns.length) {
        return `<p class="whitespace-pre-line break-words text-xs leading-5 text-slate-100">${escapeHtml(text)}</p>`;
    }

    return `
        <div class="space-y-1.5" data-speaker-turns>
            ${turns.map((turn) => `
                <div class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-2" data-speaker-id="${escapeHtml(String(turn?.speakerId || ''))}">
                    <span class="whitespace-nowrap text-xs font-semibold leading-5 text-cyan-300">${escapeHtml(speakerLabelOf(turn))}:</span>
                    <span class="break-words text-xs leading-5 text-slate-100">${escapeHtml(String(turn?.text || ''))}</span>
                </div>
            `).join('')}
        </div>
    `;
};

