// Maps the consistent snake_case shape returned by the backend presenters
// (AudioChunkRowPresenter / CleanTranscriptChunkPresenter) into the camelCase
// fields the UI controllers consume. Speaker turns, display text, and the
// "useful" flag arrive precomputed from the server.

const missing = (emptyAsNull) => (emptyAsNull ? null : '');

export const normalizeStoredItem = (item = {}, options = {}) => {
    const {
        playUrlBase = '',
        deleteUrlBase = '',
        defaultSourceType = 'upload',
        emptyAsNull = false,
    } = options;

    const fallback = missing(emptyAsNull);
    const id = item.id;

    return {
        ...item,
        id,
        rangeLabel: item.rangeLabel || item.range_label || fallback,
        categoryName: item.categoryName || item.category_name || fallback,
        playUrl: item.play_url || item.playUrl || (id ? `${playUrlBase}/${id}/audio` : fallback),
        deleteUrl: item.delete_url || item.deleteUrl || (id ? `${deleteUrlBase}/${id}` : fallback),
        translatedText: item.translatedText || item.translated_text || fallback,
        clipStartMs: Number(item.clipStartMs || item.clip_start_ms || 0),
        clipEndMs: Number(item.clipEndMs || item.clip_end_ms || 0),
        sourceType: item.sourceType || item.source_type || defaultSourceType,
        isUseful: item.is_useful ?? (String(item.translatedText || item.translated_text || '').trim() !== ''),
        displayText: item.display_text ?? (String(item.translatedText || item.translated_text || '').trim()),
        speakerTurns: Array.isArray(item.speaker_turns) ? item.speaker_turns : [],
        speakerLabels: Array.isArray(item.speaker_labels) ? item.speaker_labels : [],
    };
};
