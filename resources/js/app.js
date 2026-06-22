$(function () {
    const $body = $('body');
    const browserDownloadTextFile = (filename, content) => {
        const blob = new Blob([content], { type: 'text/plain;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    };

    const saveTextExport = async (filename, content) => {
        const invoke = window.__TAURI__?.core?.invoke;

        if (typeof invoke !== 'function') {
            browserDownloadTextFile(filename, content);
            return;
        }

        try {
            const path = await invoke('save_text_export_with_dialog', { content });

            if (path && typeof window.showNotification === 'function') {
                window.showNotification(`Export saved to ${path}`, 'success');
            }
        } catch (error) {
            if (typeof window.showNotification === 'function') {
                const message = String(error || '').trim();
                window.showNotification(message || 'Could not save the export. Please try again.', 'error');
            }
        }
    };

    const openExternalLink = async (url) => {
        const invoke = window.__TAURI__?.core?.invoke;

        if (typeof invoke !== 'function') {
            window.open(url, '_blank', 'noopener,noreferrer');
            return;
        }

        await invoke('open_external_url', { url });
    };

    $(document).on('click', 'a[target="_blank"]', function (event) {
        const href = String($(this).attr('href') || '').trim();

        if (!/^https?:\/\//i.test(href)) {
            return;
        }

        event.preventDefault();

        openExternalLink(href).catch(() => {
            window.open(href, '_blank', 'noopener,noreferrer');
        });
    });

    if ($body.data('page') === 'upload') {
        const uploadFrontendVersion = 'upload-flow-v4-queue-parity';
        const uploadStateStorageKey = 'ai-transcriber-upload-session';
        const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';
        const notify = (message, type = 'success') => {
            if (typeof window.showNotification === 'function') {
                window.showNotification(message, type);
            }
        };
        const notifyError = (message) => notify(message, 'error');
        const pause = (milliseconds) => new Promise((resolve) => {
            window.setTimeout(resolve, milliseconds);
        });
        const tauriInvoke = () => window.__TAURI__?.core?.invoke;

        if (csrfToken) {
            $.ajaxSetup({
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                },
            });
        }

        const uploadUrl = String($body.attr('data-upload-audio-url') || '');
        const audioChunkUrl = String($body.attr('data-audio-chunk-url') || '');
        const storedUrl = String($body.attr('data-stored-url') || '');
        const furnishUrl = String($body.attr('data-furnish-url') || '');
        const defaultUserId = Number($body.attr('data-default-user-id') || 1);
        const $form = $('form');
        const $categoryInput = $('[data-upload-category]');
        const $categorySuggestions = $('[data-upload-category-suggestions]');
        const $fileInput = $('[data-upload-file]');
        const $fileName = $('[data-upload-file-name]');
        const $fileMeta = $('[data-upload-file-meta]');
        const $duration = $('[data-upload-duration]');
        const $sections = $('[data-upload-sections]');
        const $status = $('[data-upload-status]');
        const $chunkSize = $('[data-upload-chunk-size]');
        const $queueButton = $('[data-upload-queue]');
        const $continueButton = $('[data-upload-continue]');
        const $retryButton = $('[data-upload-retry]');
        const $cancelButton = $('[data-upload-cancel]');
        const $queueList = $('[data-upload-queue-list]');
        const $activeCount = $('[data-upload-active-count]');
        const $progress = $('[data-upload-progress]');
        const $progressLabel = $('[data-upload-progress-label]');
        const $progressPercent = $('[data-upload-progress-percent]');
        const $transcriptCategory = $('[data-upload-transcript-category]');
        const $transcriptCount = $('[data-upload-transcript-count]');
        const $transcriptList = $('[data-upload-transcript-list]');
        const $exportButton = $('[data-export-upload]');
        const $exportMode = $('[data-export-upload-mode]');
        const $furnishButton = $('[data-furnish-upload]');
        const $cleanerState = $('[data-cleaner-state]');
        const $cleanerProgressLabel = $('[data-cleaner-progress-label]');
        const $cleanerProgressPercent = $('[data-cleaner-progress-percent]');
        const $cleanerProgressBar = $('[data-cleaner-progress-bar]');
        const $cleanerProgressNote = $('[data-cleaner-progress-note]');

        let selectedFile = null;
        let selectedDurationMs = 0;
        let preparedSections = [];
        let metadataUrl = null;
        let uploadInFlight = false;
        let currentSessionId = '';
        let currentCategoryName = '';
        let cancelRequested = false;
        let sourceUploadXhr = null;
        let activeSectionXhr = null;
        let cleanedSections = [];
        let furnishInFlight = false;
        let cleanerStatus = 'Waiting';
        let cleanerCompletedBatches = 0;
        let uploadCategories = [];
        let uploadStoredItems = [];
        let uploadCleanedCategoryName = '';
        let activeUploadAudio = null;
        let activeUploadAudioId = null;

        const formatClock = (milliseconds) => {
            const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
            const hours = Math.floor(totalSeconds / 3600);
            const minutes = Math.floor(totalSeconds / 60);
            const seconds = totalSeconds % 60;

            if (hours > 0) {
                return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            }

            return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        };

        const formatBytes = (bytes) => {
            if (!Number.isFinite(bytes) || bytes <= 0) {
                return '0 MB';
            }

            const units = ['B', 'KB', 'MB', 'GB'];
            const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
            const value = bytes / (1024 ** index);

            return `${value.toFixed(value >= 10 ? 0 : 1)} ${units[index]}`;
        };

        const getChunkLengthMs = () => Number($chunkSize.val() || 60) * 1000;

        const slugify = (value) => String(value || 'transcription')
            .trim()
            .toLowerCase()
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-+|-+$/g, '') || 'transcription';

        const escapeHtml = (value) => $('<div>').text(String(value || '')).html();

        const hasUsefulTranscriptText = (value) => {
            const normalized = String(value || '').trim().toLowerCase();

            return normalized !== '' && normalized !== 'no speech detected' && normalized !== 'no speech detected.';
        };

        const hasUsefulUploadTranscript = (item) => hasUsefulTranscriptText(
            item?.translatedText || item?.translated_text || item?.text || '',
        );

        const downloadTextFile = (filename, content) => {
            saveTextExport(filename, content);
        };

        const getUploadCategory = () => String($categoryInput.val() || '').trim();

        const hasCleanedUploadTranscriptForCategory = (categoryName) => (
            uploadCleanedCategoryName
            && String(uploadCleanedCategoryName).toLowerCase() === String(categoryName || '').toLowerCase()
        );

        const syncTranscriptCategory = () => {
            $transcriptCategory.text(getUploadCategory() || selectedFile?.name || 'Upload audio');
        };

        const saveUploadState = () => {
            if (!currentSessionId) {
                return;
            }

            try {
                window.localStorage.setItem(uploadStateStorageKey, JSON.stringify({
                    sessionId: currentSessionId,
                    categoryName: currentCategoryName || getUploadCategory(),
                    durationMs: selectedDurationMs,
                    sections: preparedSections,
                    savedAt: Date.now(),
                }));
            } catch (error) {
            }
        };

        const clearUploadState = () => {
            try {
                window.localStorage.removeItem(uploadStateStorageKey);
            } catch (error) {
            }
        };

        const hasRetryableSections = () => preparedSections.some((section) => ['Failed', 'Cancelled'].includes(section.status));

        const hasUnfinishedSections = () => preparedSections.some((section) => section.status !== 'Complete');

        const hasCancelableSections = () => preparedSections.some((section) => ['Waiting', 'Processing'].includes(section.status));

        const syncUploadControls = () => {
            const hasSession = Boolean(currentSessionId);

            $queueButton.prop('disabled', uploadInFlight || !selectedFile || hasSession);
            $continueButton.prop('disabled', uploadInFlight || !hasSession || !hasUnfinishedSections());
            $retryButton.prop('disabled', uploadInFlight || !hasSession || !hasRetryableSections());
            $cancelButton.prop('disabled', !uploadInFlight && !hasCancelableSections());
            $categoryInput.prop('disabled', uploadInFlight);
        };

        const renderEmptyQueue = (message = 'No pending recordings yet.') => {
            $queueList.html(`
                <div data-upload-empty class="rounded-lg border border-dashed border-cyan-300/20 bg-cyan-300/5 p-4">
                    <p class="text-sm text-slate-200">${message}</p>
                </div>
            `);
        };

        const normalizeUploadStoredItem = (item) => ({
            ...item,
            id: item.id,
            rangeLabel: item.rangeLabel || item.range_label || '',
            categoryName: item.categoryName || item.category_name || '',
            playUrl: item.play_url || item.playUrl || '',
            deleteUrl: item.delete_url || item.deleteUrl || '',
            translatedText: item.translatedText || item.translated_text || '',
            clipStartMs: Number(item.clipStartMs || item.clip_start_ms || 0),
            clipEndMs: Number(item.clipEndMs || item.clip_end_ms || 0),
            sourceType: item.sourceType || item.source_type || 'upload',
        });

        const getUploadStoredItemsForCategory = () => {
            const selectedCategory = getUploadCategory().toLowerCase();

            if (!selectedCategory) {
                return [];
            }

            return uploadStoredItems
                .filter((item) => String(item.categoryName || '').toLowerCase() === selectedCategory)
                .filter(hasUsefulUploadTranscript)
                .sort((first, second) => {
                    const firstStart = Number(first.clipStartMs || 0);
                    const secondStart = Number(second.clipStartMs || 0);

                    return firstStart === secondStart
                        ? Number(first.id || 0) - Number(second.id || 0)
                        : firstStart - secondStart;
                });
        };

        const rememberStoredUploadItem = (item) => {
            const normalized = normalizeUploadStoredItem(item);

            if (!normalized.id) {
                return;
            }

            uploadStoredItems = [
                ...uploadStoredItems.filter((entry) => String(entry.id) !== String(normalized.id)),
                normalized,
            ];
        };

        const syncUploadCategoriesFromStoredItems = () => {
            uploadCategories = [...new Set(uploadStoredItems
                .map((item) => String(item.categoryName || '').trim())
                .filter(Boolean))]
                .sort((first, second) => first.localeCompare(second));
        };

        const setUploadStoredItemPlaybackState = (itemId, playing) => {
            const $row = $transcriptList.find(`[data-upload-stored-item="${itemId}"]`);
            if (!$row.length) {
                return;
            }

            $row.find('[data-upload-stored-play-icon="play"]').toggleClass('hidden', playing);
            $row.find('[data-upload-stored-play-icon="pause"]').toggleClass('hidden', !playing);
            $row.find('[data-upload-stored-play-label]').text(playing ? 'Pause' : 'Play');
            $row.toggleClass('border-cyan-300/20 bg-cyan-300/5', playing);
        };

        const stopActiveUploadAudio = () => {
            if (!activeUploadAudio) {
                return;
            }

            const itemId = activeUploadAudioId;
            activeUploadAudio.pause();
            activeUploadAudio.currentTime = 0;

            if (itemId) {
                setUploadStoredItemPlaybackState(itemId, false);
            }

            activeUploadAudio = null;
            activeUploadAudioId = null;
        };

        const playUploadStoredItem = (item) => {
            if (activeUploadAudioId === item.id && activeUploadAudio) {
                if (activeUploadAudio.paused) {
                    activeUploadAudio.play();
                    setUploadStoredItemPlaybackState(item.id, true);
                } else {
                    activeUploadAudio.pause();
                    setUploadStoredItemPlaybackState(item.id, false);
                }

                return;
            }

            stopActiveUploadAudio();

            activeUploadAudio = new Audio(item.playUrl);
            activeUploadAudio.preload = 'metadata';
            activeUploadAudioId = item.id;

            activeUploadAudio.addEventListener('ended', () => {
                setUploadStoredItemPlaybackState(item.id, false);
                activeUploadAudio = null;
                activeUploadAudioId = null;
            });
            activeUploadAudio.addEventListener('pause', () => {
                if (activeUploadAudioId === item.id && activeUploadAudio?.paused) {
                    setUploadStoredItemPlaybackState(item.id, false);
                }
            });

            activeUploadAudio.play();
            setUploadStoredItemPlaybackState(item.id, true);
        };

        const deleteUploadStoredItem = (item) => {
            if (!item.deleteUrl) {
                notifyError('Could not remove this clip right now.');
                return;
            }

            if (activeUploadAudioId === item.id) {
                stopActiveUploadAudio();
            }

            $.ajax({
                url: item.deleteUrl,
                method: 'DELETE',
                success: () => {
                    uploadStoredItems = uploadStoredItems.filter((entry) => String(entry.id) !== String(item.id));
                    cleanedSections = cleanedSections.filter((section) => String(section.audioChunkId) !== String(item.id));
                    syncUploadCategoriesFromStoredItems();
                    renderTranscript();
                    updateCleanerProgress();
                    refreshUploadCategorySuggestions();
                },
                error: () => {
                    notifyError('Could not remove this clip right now.');
                },
            });
        };

        const getCleanerBatchCount = () => {
            return getCleanerBatches().length;
        };

        const getCleanerBatches = () => {
            const batches = new Map();

            getUploadStoredItemsForCategory().forEach((section) => {
                const startMs = Math.max(0, Number(section.clipStartMs || section.clip_start_ms || 0));
                const windowIndex = Math.floor(startMs / (60 * 1000));

                if (!batches.has(windowIndex)) {
                    batches.set(windowIndex, {
                        windowIndex,
                        startMs: windowIndex * 60 * 1000,
                    });
                }
            });

            return Array.from(batches.values()).sort((first, second) => first.windowIndex - second.windowIndex);
        };

        const updateCleanerProgress = () => {
            const total = getCleanerBatchCount();
            const cleanedCount = cleanedSections.length;
            const rawCount = getUploadStoredItemsForCategory().length;
            const hasCleanedCategory = hasCleanedUploadTranscriptForCategory(getUploadCategory());
            const isComplete = total > 0 && hasCleanedCategory && cleanedCount >= rawCount;
            const done = isComplete ? total : 0;
            const activeDone = cleanerStatus === 'Furnishing' ? cleanerCompletedBatches : done;
            const percent = total > 0 ? Math.min(100, Math.round((activeDone / total) * 100)) : 0;

            if ((total === 0 || !hasCleanedCategory) && cleanerStatus !== 'Furnishing') {
                cleanerStatus = 'Waiting';
            }

            if (isComplete && cleanerStatus !== 'Furnishing') {
                cleanerStatus = 'Complete';
            }

            $cleanerState.text(cleanerStatus);
            $cleanerProgressLabel.text(`${cleanerStatus === 'Furnishing' ? cleanerCompletedBatches : activeDone} / ${total} batches`);
            $cleanerProgressPercent.text(`${percent}%`);
            $cleanerProgressBar.css('width', `${percent}%`);

            if (cleanerStatus === 'Furnishing') {
                $cleanerProgressNote.text(`Cleaning ${total} one-minute ${total === 1 ? 'batch' : 'batches'}.`);
                return;
            }

            if (cleanerStatus === 'Complete') {
                $cleanerProgressNote.text(`${cleanedCount} cleaned ${cleanedCount === 1 ? 'section' : 'sections'} ready for export.`);
                return;
            }

            if (cleanerStatus === 'Failed') {
                $cleanerProgressNote.text('Cleaning failed. You can furnish the transcript again.');
                return;
            }

            $cleanerProgressNote.text(total > 0
                ? `${total} one-minute ${total === 1 ? 'batch is' : 'batches are'} ready to clean.`
                : 'Cleaned transcript will be prepared in one-minute batches after raw transcription is ready.');
        };

        const updateProgress = () => {
            const total = preparedSections.length;
            const complete = preparedSections.filter((section) => section.status === 'Complete').length;
            const visible = preparedSections.filter((section) => section.status !== 'Complete').length;
            const percent = total > 0 ? Math.round((complete / total) * 100) : 0;

            $activeCount.text(`${visible} parked`);
            $progressLabel.text(`${complete} / ${total} sections`);
            $progressPercent.text(`${percent}%`);
            $progress.css('width', `${percent}%`);
            updateCleanerProgress();
            syncUploadControls();
            saveUploadState();
        };

        const renderTranscript = () => {
            const selectedCategory = getUploadCategory();
            const useCleaned = $exportMode.val() === 'clean';
            const rawItems = getUploadStoredItemsForCategory();
            const completed = useCleaned
                ? (hasCleanedUploadTranscriptForCategory(selectedCategory)
                    ? cleanedSections.filter((section) => hasUsefulTranscriptText(section.cleanText || section.clean_text || ''))
                    : [])
                : rawItems;

            $transcriptCount.text(String(rawItems.length));

            if (!completed.length) {
                $transcriptList.html(`
                    <div data-upload-transcript-empty class="w-full py-4">
                        <p class="text-sm text-slate-200">${!selectedCategory ? 'Choose a category.' : (useCleaned && rawItems.length ? 'Furnish the transcript before viewing cleaned text.' : 'No entries yet.')}</p>
                    </div>
                `);
                return;
            }

            $transcriptList.html(completed.map((section) => {
                const itemId = section.id || section.audioChunkId || section.audio_chunk_id || '';
                const playableItem = rawItems.find((item) => String(item.id) === String(itemId));
                const translatedText = useCleaned
                    ? (section.cleanText || section.clean_text || '')
                    : (section.translatedText || section.translated_text || section.text || '');

                return `
                <article data-upload-stored-item="${itemId}" class="w-full border-b border-white/8 py-4 last:border-b-0">
                    <div class="flex w-full flex-col gap-4 md:flex-row md:items-start md:gap-6">
                        <div class="flex shrink-0 items-start gap-3 md:w-[16rem] md:pl-1">
                            <p class="max-w-full text-sm font-medium leading-7 tracking-[0.2em] text-cyan-300 md:text-base">${section.rangeLabel || section.range_label || ''}</p>
                            ${playableItem ? `
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        data-upload-stored-action="play"
                                        class="group inline-flex cursor-pointer items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] p-3 text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10"
                                    >
                                        <span data-upload-stored-play-icon="play" class="text-emerald-300">
                                            <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                                <path d="M8 5.14v13.72c0 .84.92 1.35 1.63.91l10.72-6.86a1.1 1.1 0 0 0 0-1.86L9.63 4.23A1.08 1.08 0 0 0 8 5.14Z" />
                                            </svg>
                                        </span>
                                        <span data-upload-stored-play-icon="pause" class="hidden text-rose-400">
                                            <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                                <rect x="6.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                                <rect x="13.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                            </svg>
                                        </span>
                                        <span class="sr-only" data-upload-stored-play-label>Play</span>
                                    </button>
                                    <button
                                        type="button"
                                        data-upload-stored-action="remove"
                                        class="inline-flex cursor-pointer items-center justify-center rounded-lg border border-rose-400/20 bg-rose-400/10 p-3 text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15"
                                    >
                                        <span class="sr-only">Delete</span>
                                        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                            <path d="M9 3.5h6a1.5 1.5 0 0 1 1.5 1.5V6h2.5a1 1 0 1 1 0 2h-.58l-.78 10.01A2.5 2.5 0 0 1 15.17 20H8.83a2.5 2.5 0 0 1-2.49-1.99L5.56 8H5a1 1 0 1 1 0-2h2.5V5A1.5 1.5 0 0 1 9 3.5Zm1 2V6h4V5.5h-4ZM7.58 8l.77 9.83c.04.45.42.79.87.79h6.56c.45 0 .83-.34.87-.79L17.42 8H7.58Z" />
                                        </svg>
                                    </button>
                                </div>
                            ` : ''}
                        </div>
                        <div class="min-w-0 flex-1 md:pt-1">
                            <p class="text-[0.98rem] leading-7 text-slate-100">${translatedText}</p>
                        </div>
                    </div>
                </article>
                `;
            }).join(''));

            if (activeUploadAudioId) {
                setUploadStoredItemPlaybackState(activeUploadAudioId, true);
            }
        };

        const exportTranscript = () => {
            const selectedCategory = getUploadCategory();
            const useCleaned = $exportMode.val() === 'clean';
            const completed = useCleaned
                ? (hasCleanedUploadTranscriptForCategory(selectedCategory)
                    ? cleanedSections.filter((section) => hasUsefulTranscriptText(section.cleanText || section.clean_text || ''))
                    : [])
                : getUploadStoredItemsForCategory();

            if (!completed.length) {
                notifyError(useCleaned
                    ? 'Furnish the transcript before exporting the cleaned version.'
                    : 'No transcription is ready to export yet.');
                return;
            }

            const content = completed
                .filter((section) => hasUsefulTranscriptText(section.cleanText || section.clean_text || section.translatedText || section.translated_text || section.text || ''))
                .map((section) => `${section.rangeLabel || section.range_label || ''}\n${section.cleanText || section.clean_text || section.translatedText || section.translated_text || section.text || ''}`)
                .join('\n\n');

            downloadTextFile(`${slugify(selectedCategory || selectedFile?.name)}-${useCleaned ? 'cleaned' : 'raw'}-transcription.txt`, content);
        };

        const mergeCleanedRows = (rows) => {
            const existing = new Map(cleanedSections.map((section) => [String(section.audioChunkId), section]));

            rows.forEach((row) => {
                existing.set(String(row.audio_chunk_id), {
                    audioChunkId: row.audio_chunk_id,
                    rangeLabel: row.range_label || '',
                    cleanText: row.clean_text || '',
                    cleanTimestamps: row.clean_timestamps || [],
                });
            });

            cleanedSections = Array.from(existing.values())
                .sort((first, second) => Number(first.audioChunkId || 0) - Number(second.audioChunkId || 0));
        };

        const furnishTranscript = async () => {
            const categoryName = getUploadCategory();

            if (!categoryName) {
                notifyError('Choose a category before furnishing the transcript.');
                return;
            }

            if (!getUploadStoredItemsForCategory().length) {
                notifyError('No raw transcript is ready to furnish yet.');
                return;
            }

            if (furnishInFlight || !furnishUrl) {
                return;
            }

            furnishInFlight = true;
            cleanerStatus = 'Furnishing';
            cleanerCompletedBatches = 0;
            uploadCleanedCategoryName = categoryName;
            cleanedSections = [];
            $furnishButton.prop('disabled', true).text('Furnishing');
            updateCleanerProgress();

            try {
                const batches = getCleanerBatches();
                const total = batches.length;

                for (let batchIndex = 0; batchIndex < batches.length; batchIndex += 1) {
                    const batch = batches[batchIndex];
                    const response = await $.ajax({
                        url: furnishUrl,
                        method: 'POST',
                        data: {
                            user_id: defaultUserId,
                            category_name: categoryName,
                            window_index: batch.windowIndex,
                        },
                    });
                    const rows = Array.isArray(response?.data) ? response.data : [];

                    mergeCleanedRows(rows);
                    cleanerCompletedBatches = batchIndex + 1;
                    updateCleanerProgress();
                    renderTranscript();

                    if (batchIndex < total - 1) {
                        await pause(4000);
                    }
                }

                cleanerStatus = 'Complete';
                updateCleanerProgress();
                renderTranscript();
                notify('Transcript furnished.');
            } catch (xhr) {
                cleanerStatus = 'Failed';
                updateCleanerProgress();
                notifyError(String(xhr?.responseJSON?.message || 'Transcript could not be furnished.'));
            } finally {
                furnishInFlight = false;
                $furnishButton.prop('disabled', false).text('Furnish Transcript');
            }
        };

        const renderQueue = () => {
            const visibleSections = preparedSections.filter((section) => section.status !== 'Complete');

            if (!visibleSections.length) {
                const message = preparedSections.some((section) => section.status === 'Complete')
                    ? 'No pending recordings yet.'
                    : selectedFile ? 'No pending recordings yet.' : undefined;
                renderEmptyQueue(message);
                updateProgress();
                renderTranscript();
                return;
            }

            $queueList.html(visibleSections.map((section) => `
                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <p class="text-xs uppercase tracking-[0.3em] text-cyan-300">Clip ${section.index}</p>
                            <p class="mt-2 text-lg font-semibold text-white">${section.rangeLabel}</p>
                            <p class="mt-1 text-xs uppercase tracking-[0.22em] text-slate-500">${section.preparedMeta || ''}</p>
                        </div>
                        <span class="rounded-full border border-white/10 bg-white/[0.04] px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.24em] text-slate-300">
                            ${section.status}
                        </span>
                    </div>
                </article>
            `).join(''));

            updateProgress();
            renderTranscript();
        };

        const buildSections = () => {
            if (!selectedFile) {
                return [];
            }

            const chunkLengthMs = getChunkLengthMs();
            const durationMs = selectedDurationMs > 0 ? selectedDurationMs : chunkLengthMs;
            const count = Math.max(1, Math.ceil(durationMs / chunkLengthMs));

            return Array.from({ length: count }, (_, index) => {
                const startMs = index * chunkLengthMs;
                const endMs = Math.min((index + 1) * chunkLengthMs, durationMs);

                return {
                    index: index + 1,
                    startMs,
                    endMs,
                    rangeLabel: `${formatClock(startMs)}-${formatClock(endMs)}`,
                    status: 'Waiting',
                    text: '',
                };
            });
        };

        const syncPlan = () => {
            const chunkLengthMs = getChunkLengthMs();
            const durationMs = selectedDurationMs > 0 ? selectedDurationMs : 0;
            const count = selectedFile
                ? Math.max(1, Math.ceil((durationMs || chunkLengthMs) / chunkLengthMs))
                : 0;

            $duration.text(durationMs > 0 ? formatClock(durationMs) : '--:--');
            $sections.text(String(count));
            $status.text(selectedFile ? 'Ready' : 'Ready');
            $queueButton.prop('disabled', uploadInFlight || !selectedFile);
            syncUploadControls();
        };

        const syncUploadCategoryOptions = () => [...new Set(uploadCategories.filter(Boolean))];

        const renderUploadCategorySuggestions = () => {
            const categories = syncUploadCategoryOptions();
            const currentValue = getUploadCategory().toLowerCase();
            const filtered = currentValue
                ? categories.filter((category) => category.toLowerCase().includes(currentValue))
                : categories;

            if (!filtered.length) {
                $categorySuggestions.html('<div class="px-3 py-2 text-sm text-slate-500">No categories yet.</div>');
                return;
            }

            $categorySuggestions.html(filtered
                .map((category) => `
                    <button
                        type="button"
                        data-upload-category-pick="${escapeHtml(category)}"
                        class="flex w-full cursor-pointer items-center rounded-lg px-3 py-2 text-left text-sm text-white transition hover:bg-white/8"
                    >
                        ${escapeHtml(category)}
                    </button>
                `)
                .join(''));
        };

        const openUploadCategorySuggestions = () => {
            renderUploadCategorySuggestions();
            $categorySuggestions.removeClass('hidden');
        };

        const closeUploadCategorySuggestions = () => {
            $categorySuggestions.addClass('hidden');
        };

        const refreshUploadCategorySuggestions = () => {
            if (!$categorySuggestions.hasClass('hidden')) {
                renderUploadCategorySuggestions();
            }
        };

        const rememberUploadCategory = (categoryName) => {
            const category = String(categoryName || '').trim();

            if (!category || uploadCategories.some((item) => item.toLowerCase() === category.toLowerCase())) {
                return;
            }

            uploadCategories = [...uploadCategories, category].sort((first, second) => first.localeCompare(second));
            refreshUploadCategorySuggestions();
        };

        const loadUploadCategories = () => {
            if (!storedUrl) {
                uploadCategories = [];
                uploadStoredItems = [];
                renderTranscript();
                return;
            }

            $.getJSON(storedUrl)
                .done((response) => {
                    const items = Array.isArray(response?.data) ? response.data : [];
                    uploadStoredItems = items
                        .map(normalizeUploadStoredItem)
                        .filter((item) => item.sourceType === 'upload')
                        .filter(hasUsefulUploadTranscript);
                    syncUploadCategoriesFromStoredItems();
                    renderTranscript();
                    refreshUploadCategorySuggestions();
                })
                .fail(() => {
                    uploadCategories = [];
                    uploadStoredItems = [];
                    renderTranscript();
                    refreshUploadCategorySuggestions();
                });
        };

        const removeWaitingUploadSections = () => {
            const hasProcessing = preparedSections.some((section) => section.status === 'Processing');

            preparedSections = preparedSections
                .map((section) => section.status === 'Processing'
                    ? {
                        ...section,
                        status: 'Cancelled',
                        preparedMeta: 'Ready to continue',
                    }
                    : section)
                .filter((section) => section.status !== 'Waiting');

            if (!preparedSections.length) {
                currentSessionId = '';
                currentCategoryName = '';
                clearUploadState();
                return;
            }

            if (!hasProcessing && !preparedSections.some((section) => section.status !== 'Complete')) {
                clearUploadState();
                return;
            }

            saveUploadState();
        };

        const setProcessingState = (isProcessing) => {
            uploadInFlight = isProcessing;
            $status.text(isProcessing ? 'Processing' : selectedFile ? 'Ready' : 'Ready');
            syncUploadControls();
        };

        const buildUploadErrorMessage = (xhr) => {
            const serverMessage = String(xhr?.responseJSON?.message || '').trim();
            const fieldErrors = xhr?.responseJSON?.errors || {};

            if (fieldErrors.category_name?.length) {
                return 'Choose a category before processing the upload.';
            }

            if (fieldErrors.audio_file?.length) {
                return 'Choose a valid audio file before processing.';
            }

            if (serverMessage) {
                return serverMessage;
            }

            return 'Audio upload could not be processed.';
        };

        const processUploadSections = async (sessionId, categoryName) => {
            cancelRequested = false;

            if (!audioChunkUrl) {
                preparedSections = preparedSections.map((section) => ({
                    ...section,
                    status: 'Failed',
                }));
                $status.text('Failed');
                renderQueue();
                notifyError('Audio section endpoint is missing. Refresh the page and try again.');
                uploadInFlight = false;
                syncUploadControls();
                return;
            }

            for (let index = 0; index < preparedSections.length; index += 1) {
                if (cancelRequested) {
                    break;
                }

                if (preparedSections[index].status === 'Complete') {
                    continue;
                }

                preparedSections[index].status = 'Processing';
                $status.text(`Processing ${index + 1} of ${preparedSections.length}`);
                renderQueue();

                const section = preparedSections[index];
                const formData = new FormData();
                formData.append('upload_session_id', sessionId);
                formData.append('user_id', String(defaultUserId));
                formData.append('category_name', categoryName);
                formData.append('clip_index', String(section.index));
                formData.append('clip_start_ms', String(section.startMs));
                formData.append('clip_end_ms', String(section.endMs));
                formData.append('duration_ms', String(section.durationMs || Math.max(1, section.endMs - section.startMs)));
                formData.append('range_label', section.rangeLabel);

                try {
                    activeSectionXhr = $.ajax({
                        url: audioChunkUrl,
                        method: 'POST',
                        data: formData,
                        processData: false,
                        contentType: false,
                    });
                    const response = await activeSectionXhr;
                    activeSectionXhr = null;

                    const chunk = response?.data || {};
                    rememberStoredUploadItem(chunk);
                    rememberUploadCategory(categoryName);
                    preparedSections[index] = {
                        ...section,
                        status: 'Complete',
                        text: chunk.translated_text || '',
                        timestamps: chunk.transcription_timestamps || [],
                        preparedMeta: chunk.prepared_file_size_bytes
                            ? `${formatBytes(Number(chunk.prepared_file_size_bytes))} sent`
                            : '',
                    };
                    renderQueue();
                    renderTranscript();
                } catch (xhr) {
                    activeSectionXhr = null;
                    if (cancelRequested || xhr?.statusText === 'abort') {
                        if (preparedSections[index]) {
                            preparedSections[index].status = 'Cancelled';
                            preparedSections[index].preparedMeta = 'Ready to continue';
                        }
                        $status.text('Cancelled');
                        renderQueue();
                        uploadInFlight = false;
                        syncUploadControls();
                        return;
                    }

                    preparedSections[index].status = 'Failed';
                    $status.text('Failed');
                    renderQueue();
                    notifyError(buildUploadErrorMessage(xhr));
                    uploadInFlight = false;
                    syncUploadControls();
                    return;
                }
            }

            if (cancelRequested) {
                $status.text('Cancelled');
            } else {
                $status.text('Complete');
            }
            uploadInFlight = false;
            syncUploadControls();
            if (!cancelRequested) {
                rememberUploadCategory(categoryName);
                loadUploadCategories();
                notify('Audio transcription completed.');
            }
        };

        const prepareUploadSession = () => {
            const formData = new FormData();

            if (selectedFile?.localPath) {
                formData.append('local_path', selectedFile.localPath);
            } else {
                formData.append('audio_file', selectedFile);
            }

            formData.append('chunk_seconds', String($chunkSize.val() || 60));

            sourceUploadXhr = $.ajax({
                url: uploadUrl,
                method: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                xhr: () => {
                    const xhr = $.ajaxSettings.xhr();

                    if (xhr.upload) {
                        xhr.upload.addEventListener('progress', (event) => {
                            if (!event.lengthComputable) {
                                $status.text('Uploading source');
                                return;
                            }

                            const percent = Math.max(0, Math.min(100, Math.round((event.loaded / event.total) * 100)));
                            $status.text(`Uploading source ${percent}%`);
                        });
                    }

                    return xhr;
                },
            });

            return sourceUploadXhr;
        };

        const selectUploadFile = (file) => {
            selectedFile = file;
            selectedDurationMs = 0;
            preparedSections = [];
            cleanedSections = [];
            cleanerStatus = 'Waiting';
            cleanerCompletedBatches = 0;
            currentSessionId = '';
            currentCategoryName = '';
            cancelRequested = false;
            clearUploadState();
            setProcessingState(false);

            if (!file) {
                $fileName.text('Select an audio file');
                $fileMeta.text('WAV, MP3, M4A, AAC, OGG, FLAC, and other audio files.');
                syncTranscriptCategory();
                syncPlan();
                renderQueue();
                return;
            }

            $fileName.text(file.name);
            $fileMeta.text(file.localPath
                ? `${formatBytes(file.size)} selected from local path`
                : `${formatBytes(file.size)} selected`);
            syncTranscriptCategory();

            if (file.localPath) {
                syncPlan();
            } else {
                loadMetadata(file);
                syncPlan();
            }

            renderQueue();
        };

        const chooseLocalUploadFile = async () => {
            const invoke = tauriInvoke();

            if (typeof invoke !== 'function') {
                return false;
            }

            try {
                const selected = await invoke('choose_audio_file');

                if (!selected) {
                    return true;
                }

                selectUploadFile({
                    name: selected.name || 'audio',
                    size: Number(selected.size || 0),
                    localPath: selected.path || '',
                });
            } catch (error) {
                notifyError(String(error || '').trim() || 'Could not choose this audio file.');
            }

            return true;
        };

        const loadMetadata = (file) => {
            if (metadataUrl) {
                URL.revokeObjectURL(metadataUrl);
            }

            metadataUrl = URL.createObjectURL(file);
            const audio = new Audio();
            audio.preload = 'metadata';
            audio.src = metadataUrl;

            audio.addEventListener('loadedmetadata', () => {
                selectedDurationMs = Number.isFinite(audio.duration) && audio.duration > 0
                    ? audio.duration * 1000
                    : 0;

                syncPlan();
            }, { once: true });

            audio.addEventListener('error', () => {
                selectedDurationMs = 0;
                syncPlan();
            }, { once: true });
        };

        $fileInput.on('click', async function (event) {
            if (typeof tauriInvoke() !== 'function') {
                return;
            }

            event.preventDefault();
            this.value = '';
            await chooseLocalUploadFile();
        });

        $fileInput.on('change', function () {
            const file = this.files?.[0] || null;
            selectUploadFile(file);
        });

        $categoryInput.on('input change', function () {
            syncTranscriptCategory();
            renderTranscript();
            updateCleanerProgress();
            refreshUploadCategorySuggestions();
        });

        $categoryInput.on('focus click', function () {
            openUploadCategorySuggestions();
        });

        $categoryInput.on('blur', function () {
            window.setTimeout(closeUploadCategorySuggestions, 120);
        });

        $categorySuggestions.on('click', '[data-upload-category-pick]', function () {
            $categoryInput.val(String($(this).attr('data-upload-category-pick') || ''));
            syncTranscriptCategory();
            renderTranscript();
            updateCleanerProgress();
            closeUploadCategorySuggestions();
        });

        $transcriptList.on('click', '[data-upload-stored-action="play"]', function () {
            const id = String($(this).closest('[data-upload-stored-item]').attr('data-upload-stored-item') || '');
            const item = uploadStoredItems.find((entry) => String(entry.id) === id);

            if (item) {
                playUploadStoredItem(item);
            }
        });

        $transcriptList.on('click', '[data-upload-stored-action="remove"]', function () {
            const id = String($(this).closest('[data-upload-stored-item]').attr('data-upload-stored-item') || '');
            const item = uploadStoredItems.find((entry) => String(entry.id) === id);

            if (item) {
                deleteUploadStoredItem(item);
            }
        });

        $chunkSize.on('change', function () {
            preparedSections = [];
            cleanedSections = [];
            cleanerStatus = 'Waiting';
            cleanerCompletedBatches = 0;
            currentSessionId = '';
            currentCategoryName = '';
            cancelRequested = false;
            clearUploadState();
            setProcessingState(false);
            syncPlan();
            renderQueue();
        });

        $queueButton.on('click', async function () {
            if (!selectedFile || uploadInFlight || !uploadUrl || !audioChunkUrl) {
                notifyError('Upload endpoints are not ready. Refresh the page and try again.');
                return;
            }

            const categoryName = getUploadCategory();
            if (!categoryName) {
                notifyError('Choose a category before processing the upload.');
                $categoryInput.trigger('focus');
                return;
            }

            preparedSections = buildSections();
            preparedSections = preparedSections.map((section) => ({
                ...section,
                status: 'Waiting',
                preparedMeta: selectedFile.localPath ? 'Waiting for source preparation' : 'Waiting for source upload',
            }));
            cleanedSections = [];
            cleanerStatus = 'Waiting';
            cleanerCompletedBatches = 0;
            setProcessingState(true);
            $status.text(selectedFile.localPath ? 'Preparing source' : 'Uploading source 0%');
            renderQueue();

            try {
                const response = currentSessionId
                    ? { data: { session_id: currentSessionId, sections: preparedSections.map((section) => ({
                        index: section.index,
                        start_ms: section.startMs,
                        end_ms: section.endMs,
                        duration_ms: section.durationMs,
                        range_label: section.rangeLabel,
                    })) } }
                    : await prepareUploadSession();
                sourceUploadXhr = null;
                const sessionId = String(response?.data?.session_id || '');
                const sections = Array.isArray(response?.data?.sections) ? response.data.sections : [];

                if (!sessionId || !sections.length) {
                    preparedSections = preparedSections.map((section) => ({
                        ...section,
                        status: 'Failed',
                    }));
                    $status.text('Failed');
                    renderQueue();
                    notifyError('Audio upload could not be prepared.');
                    uploadInFlight = false;
                    syncUploadControls();
                    return;
                }

                currentSessionId = sessionId;
                currentCategoryName = categoryName;
                preparedSections = sections.map((section, index) => ({
                    index: Number(section.index || index + 1),
                    startMs: Number(section.start_ms || 0),
                    endMs: Number(section.end_ms || 0),
                    durationMs: Number(section.duration_ms || 1),
                    rangeLabel: section.range_label || '',
                    status: index === 0 ? 'Processing' : 'Waiting',
                    text: '',
                    timestamps: [],
                    preparedMeta: '',
                }));
                $status.text(`Processing 1 of ${preparedSections.length}`);
                renderQueue();
                saveUploadState();

                await processUploadSections(sessionId, categoryName);
            } catch (xhr) {
                sourceUploadXhr = null;
                if (cancelRequested || xhr?.statusText === 'abort') {
                    removeWaitingUploadSections();
                    $status.text('Cancelled');
                    renderQueue();
                    uploadInFlight = false;
                    syncUploadControls();
                    return;
                }

                preparedSections = preparedSections.map((section) => ({
                    ...section,
                    status: 'Failed',
                }));
                $status.text('Failed');
                renderQueue();
                notifyError(buildUploadErrorMessage(xhr));
                uploadInFlight = false;
                syncUploadControls();
            }
        });

        $continueButton.on('click', function () {
            if (!currentSessionId || uploadInFlight || !hasUnfinishedSections()) {
                return;
            }

            cancelRequested = false;
            setProcessingState(true);
            processUploadSections(currentSessionId, currentCategoryName || getUploadCategory());
        });

        $retryButton.on('click', function () {
            if (!currentSessionId || uploadInFlight || !hasRetryableSections()) {
                return;
            }

            preparedSections = preparedSections.map((section) => ['Failed', 'Cancelled'].includes(section.status)
                ? {
                    ...section,
                    status: 'Waiting',
                    preparedMeta: 'Ready to retry',
                }
                : section);
            cancelRequested = false;
            setProcessingState(true);
            renderQueue();
            processUploadSections(currentSessionId, currentCategoryName || getUploadCategory());
        });

        $cancelButton.on('click', function () {
            if (!uploadInFlight && !hasCancelableSections()) {
                return;
            }

            cancelRequested = true;

            if (sourceUploadXhr) {
                sourceUploadXhr.abort();
            }

            if (activeSectionXhr) {
                activeSectionXhr.abort();
            }

            removeWaitingUploadSections();
            $status.text('Cancelled');
            uploadInFlight = false;
            renderQueue();
            syncUploadControls();
        });

        const restoreUploadState = () => {
            try {
                const stored = JSON.parse(window.localStorage.getItem(uploadStateStorageKey) || 'null');

                if (!stored?.sessionId || !Array.isArray(stored.sections)) {
                    return;
                }

                currentSessionId = String(stored.sessionId);
                currentCategoryName = String(stored.categoryName || '');
                selectedDurationMs = Number(stored.durationMs || 0);
                preparedSections = stored.sections.map((section) => ({
                    ...section,
                    status: section.status === 'Processing' ? 'Cancelled' : section.status,
                    preparedMeta: section.status === 'Processing' ? 'Ready to continue' : section.preparedMeta,
                }));
                if (currentCategoryName && !$categoryInput.val()) {
                    $categoryInput.val(currentCategoryName);
                }
                syncPlan();
                syncTranscriptCategory();
                $status.text('Ready to continue');
                renderQueue();
            } catch (error) {
            }
        };

        $exportButton.on('click', exportTranscript);
        $exportMode.on('change', renderTranscript);
        $furnishButton.on('click', furnishTranscript);

        syncPlan();
        syncTranscriptCategory();
        loadUploadCategories();
        restoreUploadState();
        renderQueue();
        $body.attr('data-upload-frontend-version', uploadFrontendVersion);
        return;
    }

    if ($body.data('page') === 'settings') {
        const $speechProviderSelect = $('[data-speech-provider-select]');
        const $speechProviderPanels = $('[data-speech-provider-panel]');
        const syncSpeechProviderPanels = () => {
            const selectedProvider = String($speechProviderSelect.val() || 'elevenlabs');

            $speechProviderPanels.each(function () {
                const $panel = $(this);
                const isSelected = String($panel.data('speech-provider-panel') || '') === selectedProvider;

                $panel.toggleClass('hidden', !isSelected);
                $panel.find('input, select, textarea').prop('disabled', !isSelected);
            });
        };

        $speechProviderSelect.on('change', syncSpeechProviderPanels);
        syncSpeechProviderPanels();

        $('[data-settings-form]').on('submit', function () {
            const $saveButton = $(this).find('[data-settings-save]');

            if (typeof window.toggleLoading === 'function') {
                window.toggleLoading($saveButton, true);
                return;
            }

            $saveButton.prop('disabled', true);
        });

        return;
    }

    if ($body.data('page') !== 'live') {
        return;
    }

    const $button = $('[data-record-toggle]');
    const $state = $('[data-record-state]');
    const $caption = $('[data-record-caption]');
    const $playIcon = $('[data-record-icon="play"]');
    const $stopIcon = $('[data-record-icon="stop"]');
    const $queue = $('[data-audio-queue]');
    const $empty = $('[data-audio-empty]');
    const $count = $('[data-audio-count]');
    const $support = $('[data-audio-support]');
    const $storedList = $('[data-stored-list]');
    const $storedCount = $('[data-stored-count]');
    const $exportLive = $('[data-export-live]');
    const $categoryInput = $('[data-category-input]');
    const $categorySuggestions = $('[data-category-suggestions]');
    const $currentCategory = $('[data-current-category]');
    const $activeName = $('[data-audio-active-name]');
    const $activeMeta = $('[data-audio-active-meta]');
    const $activeNote = $('[data-audio-active-note]');
    const $progress = $('[data-audio-progress]');
    const $progressLabel = $('[data-audio-progress-label]');
    const $liveContinueButton = $('[data-live-continue]');
    const $liveRetryButton = $('[data-live-retry]');
    const $liveCancelButton = $('[data-live-cancel]');

    const uploadUrl = String($body.data('upload-url') || '');
    const storedUrl = String($body.data('stored-url') || '');
    const playUrlBase = String($body.data('play-url-base') || '');
    const deleteUrlBase = String($body.data('delete-url-base') || '');
    const defaultUserId = Number($body.data('default-user-id') || 1);
    const segmentLengthMs = 60 * 1000;
    const supportsRecorder = Boolean(navigator.mediaDevices && window.MediaRecorder);
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';
    const liveTimelineStorageKey = 'ai-transcriber-live-timeline-cursors';

    if (csrfToken) {
        $.ajaxSetup({
            headers: {
                'X-CSRF-TOKEN': csrfToken,
            },
        });
    }

    let isRecording = false;
    let stream = null;
    let recorder = null;
    let progressTimer = null;
    let autoStopTimer = null;
    let sessionStartedAt = 0;
    let segmentStartedAt = 0;
    let segmentIndex = 0;
    let queuedItems = [];
    let storedItems = [];
    let activeParts = [];
    let activeAudio = null;
    let activeAudioId = null;
    let activePlaybackKind = null;
    let uploadInFlight = false;
    let activeUploadXhr = null;
    let activeUploadItemId = null;
    let cancelUploadByUser = false;
    let uploadPaused = false;
    let activeCategoryName = String($body.data('default-category-name') || '').trim();

    const formatClock = (milliseconds) => {
        const totalSeconds = Math.max(0, Math.floor(milliseconds / 1000));
        const hours = Math.floor(totalSeconds / 3600);
        const minutes = Math.floor(totalSeconds / 60);
        const seconds = totalSeconds % 60;

        if (hours > 0) {
            return `${String(hours).padStart(2, '0')}:${String(minutes % 60).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
        }

        return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
    };

    const formatClipRange = (startMs, endMs) => `${formatClock(startMs)}-${formatClock(endMs)}`;

    const slugify = (value) => String(value || 'transcription')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'transcription';

    const hasUsefulTranscriptText = (value) => {
        const normalized = String(value || '').trim().toLowerCase();

        return normalized !== '' && normalized !== 'no speech detected' && normalized !== 'no speech detected.';
    };

    const hasUsefulStoredTranscript = (item) => hasUsefulTranscriptText(
        item?.translatedText || item?.translated_text || item?.text || '',
    );

    const sortByTimeDescending = (first, second) => {
        const firstStart = Number(first.clipStartMs || first.clip_start_ms || 0);
        const secondStart = Number(second.clipStartMs || second.clip_start_ms || 0);

        return firstStart === secondStart
            ? Number(second.id || second.audioChunkId || second.audio_chunk_id || 0) - Number(first.id || first.audioChunkId || first.audio_chunk_id || 0)
            : secondStart - firstStart;
    };

    const downloadTextFile = (filename, content) => {
        saveTextExport(filename, content);
    };

    const notify = (message, type = 'success') => {
        if (typeof window.showNotification === 'function') {
            window.showNotification(message, type);
        }
    };

    const notifyError = (message) => {
        notify(message, 'error');
    };

    const buildUploadErrorMessage = (xhr, item) => {
        const clipName = item ? `Clip ${item.index}` : 'This clip';
        const serverMessage = String(xhr?.responseJSON?.message || '').trim();
        const fieldErrors = xhr?.responseJSON?.errors || {};

        if (fieldErrors.category_name?.length) {
            return 'Choose a category before you start recording.';
        }

        if (fieldErrors.audio?.length) {
            return `${clipName} could not be saved because the audio file was not ready.`;
        }

        if (fieldErrors.duration_ms?.length) {
            return `${clipName} could not be saved because its length could not be measured.`;
        }

        if (fieldErrors.range_label?.length || fieldErrors.clip_index?.length || fieldErrors.clip_start_ms?.length || fieldErrors.clip_end_ms?.length) {
            return `${clipName} could not be saved because its time range is missing.`;
        }

        if (xhr?.status === 0) {
            return `${clipName} could not be saved because the app could not reach the local database.`;
        }

        if (serverMessage) {
            return serverMessage;
        }

        if (xhr?.status === 413) {
            return `${clipName} is too large to save right now.`;
        }

        if (xhr?.status >= 500) {
            return `${clipName} could not be saved because the local storage step failed.`;
        }

        return `${clipName} could not be saved. Please try again.`;
    };

    const buildStorageLoadErrorMessage = (xhr, selectedCategory) => {
        const serverMessage = String(xhr?.responseJSON?.message || '').trim();

        if (serverMessage) {
            return serverMessage;
        }

        const target = selectedCategory ? `for "${selectedCategory}"` : '';
        return `Could not load saved recordings ${target}.`;
    };

    const buildDeleteErrorMessage = () => 'Could not remove this clip right now.';

    const getCategoryName = () => String($categoryInput.val() || '').trim();

    const loadLiveTimelineCursors = () => {
        try {
            const decoded = JSON.parse(window.localStorage.getItem(liveTimelineStorageKey) || '{}');

            return decoded && typeof decoded === 'object' && !Array.isArray(decoded) ? decoded : {};
        } catch (error) {
            return {};
        }
    };

    const liveTimelineKey = (categoryName) => String(categoryName || '').trim().toLowerCase();

    const getLiveTimelineCursor = (categoryName) => {
        const key = liveTimelineKey(categoryName);

        if (!key) {
            return 0;
        }

        const cursors = loadLiveTimelineCursors();
        const endMs = Number(cursors[key] || 0);

        return Number.isFinite(endMs) ? Math.max(0, endMs) : 0;
    };

    const rememberLiveTimelineCursor = (categoryName, endMs) => {
        const key = liveTimelineKey(categoryName);
        const nextEndMs = Number(endMs || 0);

        if (!key || !Number.isFinite(nextEndMs) || nextEndMs <= 0) {
            return;
        }

        const cursors = loadLiveTimelineCursors();
        cursors[key] = Math.max(Number(cursors[key] || 0), nextEndMs);

        try {
            window.localStorage.setItem(liveTimelineStorageKey, JSON.stringify(cursors));
        } catch (error) {
        }
    };

    const exportStoredTranscription = () => {
        const useCleaned = $('[data-export-live-mode]').val() === 'clean';
        const cleanedPayload = window.liveCleanedTranscriptPayload || {};
        const selectedCategory = getCategoryName();
        const items = (useCleaned
            ? (cleanedPayload.categoryName === selectedCategory ? cleanedPayload.items || [] : [])
            : getStoredItemsForCategory())
            .filter((item) => hasUsefulTranscriptText(item.cleanText || item.clean_text || item.translatedText || item.translated_text || item.text || ''))
            .slice()
            .sort(sortByTimeDescending);

        if (!items.length) {
            notifyError(useCleaned
                ? 'Furnish the transcript before exporting the cleaned version.'
                : 'No transcription is ready to export yet.');
            return;
        }

        const content = items
            .map((item) => `${item.rangeLabel || item.range_label || ''}\n${item.cleanText || item.clean_text || item.translatedText || item.translated_text || ''}`)
            .join('\n\n');

        downloadTextFile(`${slugify(selectedCategory)}-${useCleaned ? 'cleaned' : 'raw'}-transcription.txt`, content);
    };

    const furnishStoredTranscription = () => {
        const categoryName = getCategoryName();
        const furnishUrl = String($body.attr('data-furnish-url') || '');

        if (!categoryName) {
            notifyError('Choose a category before furnishing the transcript.');
            return;
        }

        if (!getStoredItemsForCategory().length) {
            notifyError('No raw transcript is ready to furnish yet.');
            return;
        }

        if (!furnishUrl) {
            notifyError('Transcript furnishing is unavailable.');
            return;
        }

        const $button = $('[data-furnish-live]');
        $button.prop('disabled', true).text('Furnishing');

        $.ajax({
            url: furnishUrl,
            method: 'POST',
            data: {
                user_id: defaultUserId,
                category_name: categoryName,
            },
            success: (response) => {
                const rows = Array.isArray(response?.data) ? response.data : [];
                window.liveCleanedTranscriptPayload = {
                    categoryName,
                    items: rows.map((row) => ({
                        audioChunkId: row.audio_chunk_id,
                        clipStartMs: row.clip_start_ms,
                        rangeLabel: row.range_label || '',
                        cleanText: row.clean_text || '',
                        cleanTimestamps: row.clean_timestamps || [],
                    })),
                };
                renderStoredList();
                notify('Transcript furnished.');
            },
            error: (xhr) => {
                notifyError(String(xhr?.responseJSON?.message || 'Transcript could not be furnished.'));
            },
            complete: () => {
                $button.prop('disabled', false).text('Furnish Transcript');
            },
        });
    };

    const setCategoryBadge = (value) => {
        $currentCategory.text(value || 'Choose category');
    };

    const syncRecordButtonState = () => {
        const shouldDisable = !supportsRecorder;

        $button.prop('disabled', shouldDisable);
        $button.toggleClass('cursor-not-allowed opacity-60', shouldDisable);
        $button.toggleClass('hover:scale-[1.01]', !shouldDisable);
    };

    const syncCategoryUi = () => {
        setCategoryBadge(getCategoryName());

        if (!isRecording) {
            syncRecordButtonState();
            if (!uploadPaused) {
                setSupportMessage(getCategoryName() ? 'Ready' : 'Choose category');
            }
        }
    };

    const syncCategoryOptions = () => {
        return [...new Set(storedItems.map((item) => item.categoryName).filter(Boolean))];
    };

    const getStoredItemsForCategory = () => {
        const selectedCategory = getCategoryName().toLowerCase();

        if (!selectedCategory) {
            return [];
        }

        return storedItems
            .filter((item) => String(item.categoryName || '').toLowerCase() === selectedCategory)
            .filter(hasUsefulStoredTranscript)
            .sort(sortByTimeDescending);
    };

    const getLatestStoredEndMsForCategory = () => {
        const items = getStoredItemsForCategory();

        if (!items.length) {
            return 0;
        }

        return items.reduce((latest, item) => {
            const endMs = Number(item.clipEndMs || item.clip_end_ms || 0);
            return Number.isFinite(endMs) ? Math.max(latest, endMs) : latest;
        }, 0);
    };

    const getLatestTimelineEndMsForCategory = () => Math.max(
        getLatestStoredEndMsForCategory(),
        getLiveTimelineCursor(getCategoryName()),
    );

    const syncRecordingTimeline = () => {
        if (isRecording) {
            return;
        }

        const latestEndMs = getLatestTimelineEndMsForCategory();
        sessionStartedAt = latestEndMs > 0 ? Date.now() - latestEndMs : 0;
    };

    const renderCategorySuggestions = () => {
        const categories = syncCategoryOptions();
        const currentValue = getCategoryName().toLowerCase();
        const filtered = currentValue
            ? categories.filter((category) => category.toLowerCase().includes(currentValue))
            : categories;

        if (!filtered.length) {
            $categorySuggestions.html('<div class="px-3 py-2 text-sm text-slate-500">No categories yet.</div>');
            return;
        }

        $categorySuggestions.html(filtered
            .map((category) => `
                <button
                    type="button"
                    data-category-pick="${category}"
                    class="flex w-full cursor-pointer items-center rounded-lg px-3 py-2 text-left text-sm text-white transition hover:bg-white/8"
                >
                    ${category}
                </button>
            `)
            .join(''));
    };

    const openCategorySuggestions = () => {
        renderCategorySuggestions();
        $categorySuggestions.removeClass('hidden');
    };

    const closeCategorySuggestions = () => {
        $categorySuggestions.addClass('hidden');
    };

    const refreshCategorySuggestions = () => {
        if (!$categorySuggestions.hasClass('hidden')) {
            renderCategorySuggestions();
        }
    };

    const getCurrentClipRange = () => {
        if (!sessionStartedAt) {
            return '00:00-01:00';
        }

        const elapsed = Math.max(0, Date.now() - sessionStartedAt);
        const clipStart = Math.floor(elapsed / segmentLengthMs) * segmentLengthMs;
        const clipEnd = clipStart + segmentLengthMs;

        return formatClipRange(clipStart, clipEnd);
    };

    const getUploadStateMeta = (state) => {
        switch (state) {
            case 'sending':
                return {
                    label: 'Sending',
                    classes: 'border-cyan-300/20 bg-cyan-300/10 text-cyan-100',
                };
            case 'saved':
                return {
                    label: 'Saved',
                    classes: 'border-emerald-300/20 bg-emerald-300/10 text-emerald-100',
                };
            case 'error':
                return {
                    label: 'Error',
                    classes: 'border-rose-300/20 bg-rose-300/10 text-rose-100',
                };
            case 'waiting':
            default:
                return {
                    label: 'Waiting',
                    classes: 'border-white/10 bg-white/[0.05] text-slate-300',
                };
        }
    };

    const setSupportMessage = (message) => {
        $support.text(message);
    };

    const hasLiveWaitingClips = () => queuedItems.some((item) => item.uploadState === 'waiting');

    const hasLiveRetryableClips = () => queuedItems.some((item) => item.uploadState === 'error');

    const hasLiveCancelableClips = () => queuedItems.some((item) => ['waiting', 'sending'].includes(item.uploadState));

    const syncLiveControls = () => {
        $liveContinueButton.prop('disabled', uploadInFlight || !uploadPaused || !hasLiveWaitingClips());
        $liveRetryButton.prop('disabled', uploadInFlight || !hasLiveRetryableClips());
        $liveCancelButton.prop('disabled', !uploadInFlight && !hasLiveCancelableClips());
    };

    const setIdleUi = () => {
        $button.attr('data-recording', 'false').attr('aria-pressed', 'false');
        $state.text('Listening').removeClass('text-rose-300').addClass('text-cyan-300');
        $caption.text('Ready to capture').removeClass('text-rose-50').addClass('text-white');
        $playIcon.removeClass('hidden');
        $stopIcon.addClass('hidden');
        $activeName.text('Ready');
        $activeMeta.text('No audio yet');
        $activeNote.text('');
        $progress.css('width', '0%');
        $progressLabel.text('00:00:00');
        $categoryInput.prop('disabled', false);
        syncCategoryUi();
    };

    const setRecordingUi = () => {
        $button.attr('data-recording', 'true').attr('aria-pressed', 'true');
        $state.text('Recording').removeClass('text-cyan-300').addClass('text-rose-300');
        $caption.text('Stop recording').removeClass('text-white').addClass('text-rose-50');
        $playIcon.addClass('hidden');
        $stopIcon.removeClass('hidden');
        $activeMeta.text('Live');
        $categoryInput.prop('disabled', true);
        syncRecordButtonState();
        setSupportMessage(uploadInFlight ? 'Sending' : 'Live');
    };

    const updateQueueSummary = () => {
        $count.text(String(queuedItems.length));

        if ($empty.length) {
            $empty.toggleClass('hidden', queuedItems.length > 0);
        }

        syncLiveControls();
    };

    const updateStoredSummary = (count) => {
        $storedCount.text(String(count));

        $storedList.find('[data-stored-empty]').toggleClass('hidden', count > 0);
    };

    const updateQueueItemProgress = (itemId, currentMs, durationMs) => {
        const $row = $queue.find(`[data-queue-item="${itemId}"]`);
        if (!$row.length) {
            return;
        }

        const safeDuration = Math.max(durationMs || 1, 1);
        const percent = Math.max(0, Math.min(100, (currentMs / safeDuration) * 100));

        $row.find('[data-item-progress]').css('width', `${percent}%`);
        $row.find('[data-item-progress-label]').text(`${formatClock(currentMs)} / ${formatClock(safeDuration)}`);
    };

    const setQueueItemPlaybackState = (itemId, playing) => {
        const $row = $queue.find(`[data-queue-item="${itemId}"]`);
        if (!$row.length) {
            return;
        }

        $row.find('[data-play-icon="play"]').toggleClass('hidden', playing);
        $row.find('[data-play-icon="pause"]').toggleClass('hidden', !playing);
        $row.find('[data-play-label]').text(playing ? 'Pause' : 'Play');
        $row.toggleClass('border-cyan-300/20 bg-cyan-300/5', playing);
    };

    const setStoredItemPlaybackState = (itemId, playing) => {
        const $row = $storedList.find(`[data-stored-item="${itemId}"]`);
        if (!$row.length) {
            return;
        }

        $row.find('[data-stored-play-icon="play"]').toggleClass('hidden', playing);
        $row.find('[data-stored-play-icon="pause"]').toggleClass('hidden', !playing);
        $row.find('[data-stored-play-label]').text(playing ? 'Pause' : 'Play');
        $row.toggleClass('border-cyan-300/20 bg-cyan-300/5', playing);
    };

    const renderStoredList = () => {
        const selectedCategory = getCategoryName();
        const rawItems = getStoredItemsForCategory();
        const useCleaned = $('[data-export-live-mode]').val() === 'clean';
        const cleanedPayload = window.liveCleanedTranscriptPayload || {};
        const items = useCleaned
            ? (cleanedPayload.categoryName === selectedCategory
                ? (cleanedPayload.items || [])
                    .filter((item) => hasUsefulTranscriptText(item.cleanText || item.clean_text || ''))
                    .sort(sortByTimeDescending)
                : [])
            : rawItems;
        syncRecordingTimeline();
        updateStoredSummary(rawItems.length);
        renderCategorySuggestions();

        const html = items
            .map((item) => {
                const rangeLabel = item.rangeLabel || item.range_label || '';
                const translatedText = useCleaned
                    ? item.cleanText || item.clean_text || ''
                    : item.translatedText || item.translated_text || '';
                return `
                    <article data-stored-item="${item.id}" class="w-full border-b border-white/8 py-4 last:border-b-0">
                        <div class="flex w-full flex-col gap-4 md:flex-row md:items-start md:gap-6">
                            <div class="flex shrink-0 items-start gap-3 md:w-[16rem] md:pl-1">
                                <p class="max-w-full text-sm font-medium leading-7 tracking-[0.2em] text-cyan-300 md:text-base">${rangeLabel}</p>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        data-stored-action="play"
                                        class="group inline-flex cursor-pointer items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] p-3 text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10"
                                    >
                                        <span data-stored-play-icon="play" class="text-emerald-300">
                                            <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                                <path d="M8 5.14v13.72c0 .84.92 1.35 1.63.91l10.72-6.86a1.1 1.1 0 0 0 0-1.86L9.63 4.23A1.08 1.08 0 0 0 8 5.14Z" />
                                            </svg>
                                        </span>
                                        <span data-stored-play-icon="pause" class="hidden text-rose-400">
                                            <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                                <rect x="6.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                                <rect x="13.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                            </svg>
                                        </span>
                                        <span class="sr-only" data-stored-play-label>Play</span>
                                    </button>
                                    <button
                                        type="button"
                                        data-stored-action="remove"
                                        class="inline-flex cursor-pointer items-center justify-center rounded-lg border border-rose-400/20 bg-rose-400/10 p-3 text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15"
                                    >
                                        <span class="sr-only">Delete</span>
                                        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                            <path d="M9 3.5h6a1.5 1.5 0 0 1 1.5 1.5V6h2.5a1 1 0 1 1 0 2h-.58l-.78 10.01A2.5 2.5 0 0 1 15.17 20H8.83a2.5 2.5 0 0 1-2.49-1.99L5.56 8H5a1 1 0 1 1 0-2h2.5V5A1.5 1.5 0 0 1 9 3.5Zm1 2V6h4V5.5h-4ZM7.58 8l.77 9.83c.04.45.42.79.87.79h6.56c.45 0 .83-.34.87-.79L17.42 8H7.58Z" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="min-w-0 flex-1 md:pt-1">
                                <p class="text-[0.98rem] leading-7 text-slate-100">${translatedText}</p>
                            </div>
                        </div>
                    </article>
                `;
            })
            .join('');

        $storedList.html(`
            <div data-stored-empty class="w-full py-4 ${items.length > 0 ? 'hidden' : ''}">
                <p class="text-sm text-slate-200">${useCleaned && rawItems.length ? 'Furnish the transcript before viewing cleaned text.' : (selectedCategory ? 'No entries yet.' : 'Choose a category.')}</p>
            </div>
            ${html}
        `);

        syncCategoryOptions();

        if (activeAudioId && activePlaybackKind === 'stored') {
            setStoredItemPlaybackState(activeAudioId, true);
        }
    };

    const setQueueItemUploadState = (itemId, state) => {
        const item = queuedItems.find((entry) => entry.id === itemId);
        if (!item) {
            return;
        }

        item.uploadState = state;
        syncLiveControls();

        const meta = getUploadStateMeta(state);
        const $row = $queue.find(`[data-queue-item="${itemId}"]`);

        if ($row.length) {
            const $pill = $row.find('[data-upload-state]');
            $pill
                .text(meta.label)
                .attr('class', `inline-flex items-center rounded-full border px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.24em] ${meta.classes}`);
        }
    };

    const stopActiveAudio = (resetProgress = false) => {
        if (!activeAudio) {
            return;
        }

        const itemId = activeAudioId;
        activeAudio.pause();
        activeAudio.currentTime = 0;

        if (itemId) {
            if (activePlaybackKind === 'stored') {
                setStoredItemPlaybackState(itemId, false);
            } else {
                setQueueItemPlaybackState(itemId, false);
            }

            if (resetProgress) {
                const item = activePlaybackKind === 'stored'
                    ? storedItems.find((entry) => entry.id === itemId)
                    : queuedItems.find((entry) => entry.id === itemId);
                if (item) {
                    updateQueueItemProgress(itemId, 0, item.durationMs || 1000);
                }
            }
        }

        activeAudio = null;
        activeAudioId = null;
        activePlaybackKind = null;
    };

    const processUploadQueue = () => {
        if (uploadPaused || uploadInFlight || !uploadUrl) {
            syncLiveControls();
            return;
        }

        const nextItem = queuedItems.find((entry) => entry.uploadState === 'waiting');
        if (!nextItem) {
            if (isRecording) {
                setSupportMessage('Live');
            } else if (!uploadPaused) {
                setSupportMessage('Ready');
            }
            syncLiveControls();
            return;
        }

        uploadInFlight = true;
        activeUploadItemId = nextItem.id;
        setQueueItemUploadState(nextItem.id, 'sending');
        setSupportMessage('Sending');
        syncLiveControls();

        const formData = new FormData();
        formData.append('audio', nextItem.blob, `clip-${nextItem.index}.webm`);
        formData.append('user_id', String(defaultUserId));
        formData.append('category_name', activeCategoryName || getCategoryName());
        formData.append('clip_index', String(nextItem.index));
        formData.append('clip_start_ms', String(nextItem.clipStartMs));
        formData.append('clip_end_ms', String(nextItem.clipEndMs));
        formData.append('range_label', nextItem.rangeLabel);
        formData.append('duration_ms', String(nextItem.durationMs));

        activeUploadXhr = $.ajax({
            url: uploadUrl,
            method: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: (response) => {
                const responseData = response?.data || {};
                rememberLiveTimelineCursor(
                    activeCategoryName || getCategoryName(),
                    Number(responseData.clip_end_ms || nextItem.clipEndMs || 0),
                );
                setSupportMessage('Saved');
                loadStoredClips();
                removeQueuedItem(nextItem.id, { skipAbort: true });
            },
            error: (_xhr, status) => {
                if (status === 'abort' && cancelUploadByUser) {
                    cancelUploadByUser = false;
                    return;
                }

                setQueueItemUploadState(nextItem.id, 'error');
                uploadPaused = true;
                const errorMessage = buildUploadErrorMessage(_xhr, nextItem);
                setSupportMessage('Save failed');
                notifyError(errorMessage);
                stopRecording();
                syncLiveControls();
            },
            complete: () => {
                uploadInFlight = false;
                activeUploadXhr = null;
                activeUploadItemId = null;
                syncLiveControls();

                if (!uploadPaused) {
                    processUploadQueue();
                }
            },
        });
    };

    const removeQueuedItem = (id, options = {}) => {
        const { skipAbort = false } = options;
        const index = queuedItems.findIndex((item) => item.id === id);
        if (index === -1) {
            return;
        }

        const [item] = queuedItems.splice(index, 1);

        if (activeAudioId === id) {
            stopActiveAudio(false);
        }

        if (!skipAbort && activeUploadItemId === id && activeUploadXhr) {
            cancelUploadByUser = true;
            activeUploadXhr.abort();
        }

        if (item.url) {
            URL.revokeObjectURL(item.url);
        }

        renderQueue();

        if (!uploadPaused) {
            processUploadQueue();
        }

        syncLiveControls();
    };

    const cancelLiveQueue = () => {
        const removableItems = queuedItems.filter((item) => ['waiting', 'sending'].includes(item.uploadState));

        if (!removableItems.length && !uploadInFlight) {
            return;
        }

        if (activeUploadXhr) {
            cancelUploadByUser = true;
            activeUploadXhr.abort();
        }

        removableItems.forEach((item) => {
            if (activeAudioId === item.id && activePlaybackKind === 'live') {
                stopActiveAudio(false);
            }

            if (item.url) {
                URL.revokeObjectURL(item.url);
            }
        });

        queuedItems = queuedItems.filter((item) => !['waiting', 'sending'].includes(item.uploadState));
        uploadInFlight = false;
        activeUploadItemId = null;
        uploadPaused = hasLiveRetryableClips();
        setSupportMessage(uploadPaused ? 'Saving paused' : (isRecording ? 'Live' : 'Ready'));
        renderQueue();
        syncLiveControls();
    };

    const continueLiveQueue = () => {
        if (uploadInFlight || !hasLiveWaitingClips()) {
            return;
        }

        uploadPaused = false;
        setSupportMessage('Ready');
        syncLiveControls();
        processUploadQueue();
    };

    const retryLiveQueue = () => {
        if (uploadInFlight || !hasLiveRetryableClips()) {
            return;
        }

        queuedItems = queuedItems.map((item) => item.uploadState === 'error'
            ? {
                ...item,
                uploadState: 'waiting',
            }
            : item);
        uploadPaused = false;
        renderQueue();
        processUploadQueue();
    };

    const playQueuedItem = (item) => {
        if (activeAudioId === item.id && activeAudio) {
            if (activeAudio.paused) {
                activeAudio.play();
                setQueueItemPlaybackState(item.id, true);
            } else {
                activeAudio.pause();
                setQueueItemPlaybackState(item.id, false);
            }

            return;
        }

        stopActiveAudio(false);

        activeAudio = new Audio(item.url);
        activeAudio.preload = 'metadata';
        activeAudioId = item.id;
        activePlaybackKind = 'live';

        const syncDuration = () => {
            const durationMs = Number.isFinite(activeAudio.duration) && activeAudio.duration > 0
                ? activeAudio.duration * 1000
                : item.durationMs || 1000;

            item.durationMs = durationMs;
            updateQueueItemProgress(item.id, activeAudio.currentTime * 1000, durationMs);
        };

        activeAudio.addEventListener('loadedmetadata', syncDuration);
        activeAudio.addEventListener('timeupdate', () => {
            const durationMs = Number.isFinite(activeAudio.duration) && activeAudio.duration > 0
                ? activeAudio.duration * 1000
                : item.durationMs || 1000;

            updateQueueItemProgress(item.id, activeAudio.currentTime * 1000, durationMs);
        });
        activeAudio.addEventListener('pause', () => {
            if (activeAudioId === item.id && activeAudio.paused) {
                setQueueItemPlaybackState(item.id, false);
            }
        });
        activeAudio.addEventListener('ended', () => {
            const durationMs = Number.isFinite(activeAudio.duration) && activeAudio.duration > 0
                ? activeAudio.duration * 1000
                : item.durationMs || 1000;

            updateQueueItemProgress(item.id, durationMs, durationMs);
            setQueueItemPlaybackState(item.id, false);
            activeAudio = null;
            activeAudioId = null;
            activePlaybackKind = null;
        });

        activeAudio.play();
        setQueueItemPlaybackState(item.id, true);
    };

    const playStoredItem = (item) => {
        if (activeAudioId === item.id && activeAudio) {
            if (activeAudio.paused) {
                activeAudio.play();
                setStoredItemPlaybackState(item.id, true);
            } else {
                activeAudio.pause();
                setStoredItemPlaybackState(item.id, false);
            }

            return;
        }

        stopActiveAudio(false);

        activeAudio = new Audio(item.playUrl || `${playUrlBase}/${item.id}/audio`);
        activeAudio.preload = 'metadata';
        activeAudioId = item.id;
        activePlaybackKind = 'stored';

        activeAudio.addEventListener('ended', () => {
            setStoredItemPlaybackState(item.id, false);
            activeAudio = null;
            activeAudioId = null;
            activePlaybackKind = null;
        });
        activeAudio.addEventListener('pause', () => {
            if (activeAudioId === item.id && activeAudio.paused) {
                setStoredItemPlaybackState(item.id, false);
            }
        });

        activeAudio.play();
        setStoredItemPlaybackState(item.id, true);
    };

    const deleteStoredItem = (item) => {
        const deleteUrl = item.deleteUrl || `${deleteUrlBase}/${item.id}`;

        if (activeAudioId === item.id && activePlaybackKind === 'stored') {
            stopActiveAudio(false);
        }

        $.ajax({
            url: deleteUrl,
            method: 'DELETE',
            success: () => {
                storedItems = storedItems.filter((entry) => entry.id !== item.id);
                renderStoredList();
                setSupportMessage(isRecording ? 'Live' : 'Ready');
            },
            error: () => {
                notifyError(buildDeleteErrorMessage());
            },
        });
    };

    const loadStoredClips = () => {
        if (!storedUrl) {
            storedItems = [];
            renderStoredList();
            return;
        }

        $.getJSON(storedUrl)
            .done((response) => {
                const items = Array.isArray(response?.data) ? response.data : [];
                storedItems = items.map((item) => ({
                    ...item,
                    rangeLabel: item.rangeLabel || item.range_label || null,
                    categoryName: item.categoryName || item.category_name || null,
                    playUrl: item.play_url || `${playUrlBase}/${item.id}/audio`,
                    deleteUrl: item.delete_url || `${deleteUrlBase}/${item.id}`,
                    translatedText: item.translated_text || null,
                    sourceType: item.sourceType || item.source_type || 'live',
                }))
                    .filter((item) => item.sourceType === 'live')
                    .filter(hasUsefulStoredTranscript);
                renderStoredList();
            })
            .fail((xhr) => {
                storedItems = [];
                renderStoredList();
                const selectedCategory = getCategoryName();
                if (selectedCategory) {
                    notifyError(buildStorageLoadErrorMessage(xhr, selectedCategory));
                }
            });
    };

    const renderQueue = () => {
        updateQueueSummary();

        const items = [...queuedItems].reverse();
        const emptyHidden = queuedItems.length > 0 ? 'hidden' : '';
        const html = items
            .map((item) => {
                const uploadMeta = getUploadStateMeta(item.uploadState || 'waiting');

                return `
                    <article data-queue-item="${item.id}" class="rounded-lg border border-white/10 bg-white/[0.03] p-4 transition">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <p class="text-xs uppercase tracking-[0.3em] text-cyan-300">Clip ${item.index}</p>
                                    <span data-upload-state class="inline-flex items-center rounded-full border px-2.5 py-1 text-[0.68rem] font-semibold uppercase tracking-[0.24em] ${uploadMeta.classes}">
                                        ${uploadMeta.label}
                                    </span>
                                </div>
                                <p class="mt-2 text-lg font-semibold text-white">${item.rangeLabel}</p>
                                <div class="mt-4 h-2 overflow-hidden rounded-full bg-slate-800/80">
                                    <div data-item-progress class="h-full w-0 rounded-full bg-gradient-to-r from-cyan-400 via-emerald-300 to-amber-300 transition-[width] duration-150"></div>
                                </div>
                                <p class="mt-2 text-xs uppercase tracking-[0.24em] text-slate-500" data-item-progress-label>${item.rangeLabel}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-2">
                                <button
                                    type="button"
                                    data-action="play"
                                    class="group inline-flex cursor-pointer items-center gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-sm font-medium text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10"
                                >
                                    <span data-play-icon="play" class="text-emerald-300">
                                        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                            <path d="M8 5.14v13.72c0 .84.92 1.35 1.63.91l10.72-6.86a1.1 1.1 0 0 0 0-1.86L9.63 4.23A1.08 1.08 0 0 0 8 5.14Z" />
                                        </svg>
                                    </span>
                                    <span data-play-icon="pause" class="hidden text-rose-400">
                                        <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                            <rect x="6.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                            <rect x="13.5" y="5.5" width="4" height="13" rx="1.2"></rect>
                                        </svg>
                                    </span>
                                    <span data-play-label>Play</span>
                                </button>
                                <button
                                    type="button"
                                    data-action="remove"
                                    class="inline-flex cursor-pointer items-center rounded-lg border border-rose-400/20 bg-rose-400/10 px-3 py-2 text-sm font-medium text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15"
                                >
                                    Cancel
                                </button>
                            </div>
                        </div>
                    </article>
                `;
            })
            .join('');

        $queue.html(`
            <div data-audio-empty class="rounded-lg border border-dashed border-cyan-300/20 bg-cyan-300/5 p-4 ${emptyHidden}">
                <p class="text-sm text-slate-200">No recordings yet.</p>
            </div>
            ${html}
        `);

        queuedItems.forEach((item) => {
            updateQueueItemProgress(item.id, 0, item.durationMs || 1000);
            setQueueItemUploadState(item.id, item.uploadState || 'waiting');
        });

        if (activeAudioId) {
            setQueueItemPlaybackState(activeAudioId, true);
        }
    };

    const updateActiveProgress = () => {
        if (!isRecording || !segmentStartedAt) {
            return;
        }

        const elapsed = Date.now() - segmentStartedAt;
        const percent = Math.min(100, (elapsed / segmentLengthMs) * 100);
        const clipRange = getCurrentClipRange();

        $progress.css('width', `${percent}%`);
        $progressLabel.text(formatClock(Date.now() - sessionStartedAt));
        $activeName.text('Recording');
        $activeMeta.text('Live');
        $activeNote.text(clipRange);
    };

    const createQueuedItem = (blob, durationMs) => {
        const url = URL.createObjectURL(blob);
        const clipStartMs = Math.max(0, segmentStartedAt - sessionStartedAt);
        const clipEndMs = clipStartMs + durationMs;

        queuedItems.push({
            id: `${Date.now()}-${Math.random().toString(16).slice(2)}`,
            index: queuedItems.length + 1,
            url,
            blob,
            durationMs,
            clipStartMs,
            clipEndMs,
            rangeLabel: formatClipRange(clipStartMs, clipEndMs),
            uploadState: 'waiting',
        });

        renderQueue();
        processUploadQueue();
    };

    const finishSegment = () => {
        if (!recorder) {
            return;
        }

        const currentRecorder = recorder;
        recorder = null;

        if (currentRecorder.state !== 'inactive') {
            currentRecorder.stop();
        }
    };

    const startSegment = () => {
        if (!stream) {
            return;
        }

        activeParts = [];

        if (!sessionStartedAt) {
            sessionStartedAt = Date.now();
        }

        segmentStartedAt = Date.now();
        segmentIndex += 1;

        recorder = new MediaRecorder(stream);
        recorder.addEventListener('dataavailable', (event) => {
            if (event.data && event.data.size > 0) {
                activeParts.push(event.data);
            }
        });
        recorder.addEventListener('stop', () => {
            clearTimeout(autoStopTimer);
            autoStopTimer = null;

            const durationMs = Math.max(Date.now() - segmentStartedAt, 1);
            const blob = activeParts.length ? new Blob(activeParts, { type: recorder?.mimeType || 'audio/webm' }) : null;
            activeParts = [];

            if (blob && blob.size > 0) {
                createQueuedItem(blob, durationMs);
            }

            if (isRecording) {
                startSegment();
            } else {
                $progress.css('width', '0%');
                $progressLabel.text('00:00:00');
                $activeName.text('Ready');
                $activeMeta.text('No audio yet');
                $activeNote.text('');

                if (stream) {
                    stream.getTracks().forEach((track) => track.stop());
                    stream = null;
                }
            }
        });

        recorder.start();
        setRecordingUi();
        updateActiveProgress();
        progressTimer = progressTimer || window.setInterval(updateActiveProgress, 100);
        autoStopTimer = window.setTimeout(() => {
            if (isRecording) {
                finishSegment();
            }
        }, segmentLengthMs);
    };

    const stopRecording = () => {
        isRecording = false;
        setIdleUi();

        if (autoStopTimer) {
            clearTimeout(autoStopTimer);
            autoStopTimer = null;
        }

        if (progressTimer) {
            clearInterval(progressTimer);
            progressTimer = null;
        }

        if (recorder && recorder.state !== 'inactive') {
            finishSegment();
            return;
        }

        if (stream) {
            stream.getTracks().forEach((track) => track.stop());
            stream = null;
        }

        sessionStartedAt = 0;
        segmentStartedAt = 0;
        segmentIndex = 0;
    };

    const startRecording = async () => {
        if (!supportsRecorder) {
            return;
        }

        const chosenCategory = getCategoryName();
        if (!chosenCategory) {
            syncCategoryUi();
            notifyError('Choose a category before you start recording.');
            return;
        }

        if (uploadPaused) {
            setSupportMessage('Saving paused');
            notifyError('Saving is paused because the last clip could not be stored.');
            return;
        }

        try {
            activeCategoryName = chosenCategory;
            setCategoryBadge(activeCategoryName);
            syncRecordingTimeline();

            if (!stream) {
                stream = await navigator.mediaDevices.getUserMedia({ audio: true });
            }

            if (!sessionStartedAt) {
                sessionStartedAt = Date.now();
            }

            isRecording = true;
            startSegment();
        } catch (error) {
            setSupportMessage('Microphone blocked');
            notifyError('Microphone access is blocked. Please allow it to record audio.');
            setIdleUi();
            isRecording = false;

            if (stream) {
                stream.getTracks().forEach((track) => track.stop());
                stream = null;
            }
        }
    };

    $button.on('click', function () {
        if (isRecording) {
            stopRecording();
        } else {
            startRecording();
        }
    });

    $categoryInput.on('input change', function () {
        if (isRecording) {
            return;
        }

        syncCategoryUi();
        renderStoredList();
        refreshCategorySuggestions();
    });

    $categoryInput.on('focus click', function () {
        if (!isRecording) {
            openCategorySuggestions();
        }
    });

    $categoryInput.on('blur', function () {
        setTimeout(() => {
            closeCategorySuggestions();
        }, 120);
    });

    $categorySuggestions.on('click', '[data-category-pick]', function () {
        const category = String($(this).data('category-pick') || '').trim();
        if (!category) {
            return;
        }

        $categoryInput.val(category);
        activeCategoryName = category;
        syncCategoryUi();
        renderStoredList();
        openCategorySuggestions();
    });

    $queue.on('click', '[data-action="play"]', function () {
        const $row = $(this).closest('[data-queue-item]');
        const id = $row.data('queue-item');
        const item = queuedItems.find((entry) => entry.id === id);

        if (item) {
            playQueuedItem(item);
        }
    });

    $queue.on('click', '[data-action="remove"]', function () {
        const $row = $(this).closest('[data-queue-item]');
        const id = $row.data('queue-item');
        removeQueuedItem(id);
    });

    $liveContinueButton.on('click', continueLiveQueue);

    $liveRetryButton.on('click', retryLiveQueue);

    $liveCancelButton.on('click', cancelLiveQueue);

    $storedList.on('click', '[data-stored-action="play"]', function () {
        const $row = $(this).closest('[data-stored-item]');
        const id = $row.data('stored-item');
        const item = storedItems.find((entry) => String(entry.id) === String(id));

        if (item) {
            playStoredItem(item);
        }
    });

    $storedList.on('click', '[data-stored-action="remove"]', function () {
        const $row = $(this).closest('[data-stored-item]');
        const id = $row.data('stored-item');
        const item = storedItems.find((entry) => String(entry.id) === String(id));

        if (item) {
            deleteStoredItem(item);
        }
    });

    $exportLive.on('click', exportStoredTranscription);
    $('[data-export-live-mode]').on('change', renderStoredList);
    $('[data-furnish-live]').on('click', furnishStoredTranscription);

    if (!supportsRecorder) {
        setSupportMessage('Unavailable');
        $button
            .attr('disabled', 'disabled')
            .removeClass('hover:scale-[1.01]')
            .addClass('cursor-not-allowed opacity-60');
        $state.text('Unavailable').removeClass('text-cyan-300').addClass('text-rose-300');
        $caption.text('Recorder not supported').removeClass('text-white').addClass('text-rose-50');
        $playIcon.addClass('hidden');
        $stopIcon.addClass('hidden');
        return;
    }

    setIdleUi();
    updateQueueSummary();
    loadStoredClips();
    syncCategoryUi();
});
