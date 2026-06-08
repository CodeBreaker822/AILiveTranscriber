$(function () {
    const $body = $('body');
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
    const $categoryInput = $('[data-category-input]');
    const $categorySuggestions = $('[data-category-suggestions]');
    const $currentCategory = $('[data-current-category]');
    const $activeName = $('[data-audio-active-name]');
    const $activeMeta = $('[data-audio-active-meta]');
    const $activeNote = $('[data-audio-active-note]');
    const $progress = $('[data-audio-progress]');
    const $progressLabel = $('[data-audio-progress-label]');

    const uploadUrl = String($body.data('upload-url') || '');
    const storedUrl = String($body.data('stored-url') || '');
    const playUrlBase = String($body.data('play-url-base') || '');
    const deleteUrlBase = String($body.data('delete-url-base') || '');
    const defaultUserId = Number($body.data('default-user-id') || 1);
    const segmentLengthMs = 10000;
    const supportsRecorder = Boolean(navigator.mediaDevices && window.MediaRecorder);
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';

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

        if (xhr?.status === 413) {
            return `${clipName} is too large to save right now.`;
        }

        if (xhr?.status >= 500) {
            return `${clipName} could not be saved because the local storage step failed.`;
        }

        if (serverMessage) {
            return serverMessage;
        }

        return `${clipName} could not be saved. Please try again.`;
    };

    const buildStorageLoadErrorMessage = (selectedCategory) => {
        const target = selectedCategory ? `for "${selectedCategory}"` : '';
        return `Could not load saved recordings ${target}.`;
    };

    const buildDeleteErrorMessage = () => 'Could not remove this clip right now.';

    const getCategoryName = () => String($categoryInput.val() || '').trim();

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

        return storedItems.filter((item) => String(item.categoryName || '').toLowerCase() === selectedCategory);
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

    const syncRecordingTimeline = () => {
        if (isRecording) {
            return;
        }

        const latestEndMs = getLatestStoredEndMsForCategory();
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
                    class="flex w-full cursor-pointer items-center rounded-xl px-3 py-2 text-left text-sm text-white transition hover:bg-white/8"
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
            return '00:00-00:10';
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
        const items = getStoredItemsForCategory();
        syncRecordingTimeline();
        updateStoredSummary(items.length);
        renderCategorySuggestions();

        const html = items
            .map((item) => {
                const rangeLabel = item.rangeLabel || item.range_label || '';
                const translatedText = item.translatedText || 'None';
                return `
                    <article data-stored-item="${item.id}" class="w-full border-b border-white/8 py-4 last:border-b-0">
                        <div class="flex w-full flex-col gap-4 md:flex-row md:items-start md:gap-6">
                            <div class="flex shrink-0 items-start gap-3 md:w-[16rem] md:pl-1">
                                <p class="max-w-full text-sm font-medium leading-7 tracking-[0.2em] text-cyan-300 md:text-base">${rangeLabel}</p>
                                <div class="flex items-center gap-2">
                                    <button
                                        type="button"
                                        data-stored-action="play"
                                        class="group inline-flex cursor-pointer items-center justify-center rounded-xl border border-white/10 bg-white/[0.03] p-3 text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10"
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
                                        class="inline-flex cursor-pointer items-center justify-center rounded-xl border border-rose-400/20 bg-rose-400/10 p-3 text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15"
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
                <p class="text-sm text-slate-200">${selectedCategory ? 'No entries yet.' : 'Choose a category.'}</p>
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
            return;
        }

        const nextItem = queuedItems.find((entry) => entry.uploadState === 'waiting');
        if (!nextItem) {
            if (isRecording) {
                setSupportMessage('Live');
            } else if (!uploadPaused) {
                setSupportMessage('Ready');
            }
            return;
        }

        uploadInFlight = true;
        activeUploadItemId = nextItem.id;
        setQueueItemUploadState(nextItem.id, 'sending');
        setSupportMessage('Sending');

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
            success: () => {
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
            },
            complete: () => {
                uploadInFlight = false;
                activeUploadXhr = null;
                activeUploadItemId = null;

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
                }));
                renderStoredList();
            })
            .fail(() => {
                storedItems = [];
                renderStoredList();
                const selectedCategory = getCategoryName();
                if (selectedCategory) {
                    notifyError(buildStorageLoadErrorMessage(selectedCategory));
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
                    <article data-queue-item="${item.id}" class="rounded-[1.4rem] border border-white/10 bg-white/[0.03] p-4 transition">
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
                                    class="group inline-flex cursor-pointer items-center gap-2 rounded-xl border border-white/10 bg-white/[0.03] px-3 py-2 text-sm font-medium text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10"
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
                                    class="inline-flex cursor-pointer items-center rounded-xl border border-rose-400/20 bg-rose-400/10 px-3 py-2 text-sm font-medium text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15"
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
            <div data-audio-empty class="rounded-[1.5rem] border border-dashed border-cyan-300/20 bg-cyan-300/5 p-4 ${emptyHidden}">
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
