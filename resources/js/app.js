import { initLivePage } from './live/live-controller.js';
import { initSettingsPage } from './settings/settings-controller.js';
import { initUploadPage } from './upload/upload-controller.js';
import { clampProgressPercent } from './shared/progress.js';

$(function () {
    const $body = $('body');
    const completeDesktopStartup = () => {
        $('[data-desktop-startup-overlay]').fadeOut(160, function () {
            $(this).remove();
        });
    };
    const failDesktopStartup = (error) => {
        window.console?.error?.('Frontend could not initialize.', error);
        $('[data-desktop-startup-status]').text('Startup error');
        $('[data-desktop-startup-overlay] p').text('The interface could not finish loading. Check the developer console for details.');
    };
    const audioChunkSeconds = Math.max(1, Number($body.data('audio-chunk-seconds') || 60) || 60);
    const audioChunkLengthMs = audioChunkSeconds * 1000;
    const audioChunkDurationLabel = (() => {
        if (audioChunkSeconds % 60 === 0) {
            const minutes = audioChunkSeconds / 60;

            return minutes === 1 ? 'one-minute' : `${minutes}-minute`;
        }

        return audioChunkSeconds === 1 ? 'one-second' : `${audioChunkSeconds}-second`;
    })();
    const openExternalLink = async (url) => {
        const invoke = window.__TAURI__?.core?.invoke;

        if (typeof invoke !== 'function') {
            window.open(url, '_blank', 'noopener,noreferrer');
            return;
        }

        await invoke('open_external_url', { url });
    };

    const requestPolishInstructions = () => typeof window.requestPolishInstructions === 'function'
        ? window.requestPolishInstructions()
        : Promise.resolve(null);

    const speakerSessionReleaseUrl = String($body.attr('data-speaker-session-release-url') || '');
    const csrfToken = $('meta[name="csrf-token"]').attr('content') || '';
    const createSpeakerSessionId = () => window.crypto?.randomUUID?.()
        || `speaker-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const createTranscriptionProgressId = () => window.crypto?.randomUUID?.()
        || `progress-${Date.now()}-${Math.random().toString(16).slice(2)}`;
    const whisperProgressHandlers = new Map();
    const registerWhisperProgress = (progressId, callback) => {
        if (progressId) {
            whisperProgressHandlers.set(progressId, callback);
        }
    };
    const clearWhisperProgress = (progressId) => {
        if (progressId) {
            whisperProgressHandlers.delete(progressId);
        }
    };
    const cancelWhisperProgress = (progressId) => {
        const normalized = String(progressId || '').trim();

        if (!normalized) {
            return;
        }

        clearWhisperProgress(normalized);
        const invoke = window.__TAURI__?.core?.invoke;
        if (typeof invoke === 'function') {
            invoke('cancel_offline_whisper', { progressId: normalized }).catch(() => {});
        }
    };
    const tauriEventListen = window.__TAURI__?.event?.listen;
    if (typeof tauriEventListen === 'function') {
        tauriEventListen('offline-whisper-progress', (event) => {
            const progressId = String(event?.payload?.progress_id || '');
            const percent = clampProgressPercent(event?.payload?.percent);
            whisperProgressHandlers.get(progressId)?.(percent);
        }).catch(() => {});
    }
    const releaseSpeakerSession = (sessionId, useBeacon = false) => {
        const normalized = String(sessionId || '').trim();

        if (!normalized || !speakerSessionReleaseUrl) {
            return;
        }

        const data = new FormData();
        data.append('speaker_session_id', normalized);
        if (csrfToken) {
            data.append('_token', csrfToken);
        }

        if (useBeacon && typeof navigator.sendBeacon === 'function') {
            navigator.sendBeacon(speakerSessionReleaseUrl, data);
            return;
        }

        $.ajax({
            url: speakerSessionReleaseUrl,
            method: 'POST',
            data,
            processData: false,
            contentType: false,
        });
    };
    const $transcriptionEngine = $('[data-transcription-engine-toggle]');
    const $transcriptionEngineSwitch = $('[data-transcription-engine-switch]');
    const $languageControls = $('[data-language-control]');
    const $whisperModelControls = $('[data-whisper-model-control]');
    const $whisperModel = $('[data-whisper-model]');
    const $onlineOnlyTranscriptActions = $('[data-furnish-live], [data-furnish-upload], [data-summarize]');
    const transcriptionEngineStorageKey = 'ai-transcriber-transcription-engine';
    const whisperModelStorageKey = 'ai-transcriber-whisper-model';
    const connectivityUrl = String($body.attr('data-update-connectivity-url') || '');
    let engineConnectivityRequest = null;
    let installedWhisperModels = new Set();

    const getTranscriptionEngine = () => $transcriptionEngine.prop('checked') ? 'offline' : 'online';
    const getWhisperModel = () => String($whisperModel.val() || 'turbo');

    const syncTranscriptionControls = () => {
        const offline = getTranscriptionEngine() === 'offline';
        $languageControls.toggleClass('hidden', offline);
        $whisperModelControls.toggleClass('hidden', !offline);
        $onlineOnlyTranscriptActions.toggleClass('hidden', offline);
    };

    const applyEngineAvailability = (connectivity) => {
        const online = connectivity?.online === true && navigator.onLine !== false;
        const offlineAvailable = connectivity?.offline_available === true;
        $transcriptionEngine.prop('disabled', !offlineAvailable);
        $transcriptionEngineSwitch.toggleClass('hidden', !offlineAvailable)
            .toggleClass('flex', offlineAvailable);

        if (!online && offlineAvailable) {
            $transcriptionEngine.prop('checked', true);
        } else if (!offlineAvailable && online) {
            $transcriptionEngine.prop('checked', false);
        } else if (online && offlineAvailable) {
            const preferred = window.localStorage.getItem(transcriptionEngineStorageKey);
            $transcriptionEngine.prop('checked', preferred === 'offline');
        }

        const modelMissing = connectivity?.offline_model_available === false;
        const availability = offlineAvailable
            ? 'Offline Whisper is ready.'
            : modelMissing
                ? 'Install the large-v3-turbo Q8 model to enable offline transcription.'
                : 'Offline transcription is available in the desktop app.';
        $transcriptionEngineSwitch.attr('title', availability);
        $transcriptionEngine.trigger('transcription-engine:availability', [{ online, offlineAvailable }]);
        syncTranscriptionControls();
    };

    const refreshEngineAvailability = () => {
        if (!$transcriptionEngine.length || !connectivityUrl || engineConnectivityRequest) {
            return;
        }

        engineConnectivityRequest = $.ajax({
            url: connectivityUrl,
            method: 'GET',
            cache: false,
        })
            .done(applyEngineAvailability)
            .always(() => {
                engineConnectivityRequest = null;
            });
    };

    if ($transcriptionEngine.length) {
        $transcriptionEngine.on('change', function () {
            window.localStorage.setItem(transcriptionEngineStorageKey, getTranscriptionEngine());
            syncTranscriptionControls();
        });
        const preferredModel = window.localStorage.getItem(whisperModelStorageKey);
        if (preferredModel) {
            $whisperModel.val(preferredModel);
        }
        $whisperModel.on('change', function () {
            const model = getWhisperModel();
            window.localStorage.setItem(whisperModelStorageKey, model);
            if (!installedWhisperModels.has(model)) {
                $(window).trigger('offline-model:catalog-request', [{ model }]);
            }
        });
        $(window).on('offline-model:status', (event, payload = {}) => {
            const models = (Array.isArray(payload.models) ? payload.models : [])
                .filter((model) => model.kind !== 'diarization');
            installedWhisperModels = new Set(models
                .filter((model) => model.installed && model.supported !== false)
                .map((model) => model.id));
            models.forEach((model) => {
                const supported = model.supported !== false;
                const suffix = !supported ? 'Low memory' : model.installed ? 'Installed' : 'Download';
                $whisperModel.find(`option[value="${model.id}"]`)
                    .text(`${model.label} · ${model.size} · ${suffix}`)
                    .prop('disabled', !supported);
            });

            if (!installedWhisperModels.has(getWhisperModel())) {
                const fallback = models.find((model) => model.installed && model.supported !== false)?.id;
                if (fallback) {
                    $whisperModel.val(fallback);
                    window.localStorage.setItem(whisperModelStorageKey, fallback);
                }
            }
        });
        $(window).on('online offline offline-model:installed', refreshEngineAvailability);
        refreshEngineAvailability();
        window.setInterval(refreshEngineAvailability, 30000);
        syncTranscriptionControls();
    }

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


    const context = {
        $body,
        audioChunkSeconds,
        audioChunkLengthMs,
        audioChunkDurationLabel,
        csrfToken,
        createSpeakerSessionId,
        createTranscriptionProgressId,
        registerWhisperProgress,
        clearWhisperProgress,
        cancelWhisperProgress,
        releaseSpeakerSession,
        requestPolishInstructions,
        getTranscriptionEngine,
        getWhisperModel,
    };

    const initChatWorkspaceTemplate = () => {
        const $workspace = $('[data-transcription-chat-template]');
        if (!$workspace.length) {
            return false;
        }

        const $categoryFields = $('[data-category-input], [data-upload-category]');
        const $commandArea = $('[data-chat-command-area]');
        const normalizeMode = (mode) => {
            const value = String(mode || '').trim();

            return ['live', 'upload'].includes(value) ? value : '';
        };
        const currentMode = () => {
            return normalizeMode($workspace.attr('data-chat-mode'));
        };
        const currentProject = () => String($workspace.attr('data-chat-project') || '').trim();
        const hasProject = () => currentProject() !== '';

        const updatePendingPanel = (mode = currentMode()) => {
            const nextMode = mode === 'upload' ? 'upload' : 'live';

            $body.attr('data-chat-workspace-mode', nextMode);
            $('[data-pending-mode-panel]').addClass('hidden');
            $(`[data-pending-mode-panel="${nextMode}"]`).removeClass('hidden');
        };

        const updateTitle = () => {
            const project = currentProject();
            const mode = currentMode();

            if (!project) {
                $('[data-chat-title]').text('Welcome');
                $('[data-chat-empty-title]').text('Hi, what are we transcribing today?');
                $('[data-chat-empty-copy]').text('Start a transcript from the left, then choose Live or Upload Audio. I’ll keep the transcript here so you can polish, summarize, export, or review the processing log when it’s ready.');
                return;
            }

            if (!mode) {
                $('[data-chat-title]').text(project);
                $('[data-chat-empty-title]').text('Great. How do you want to add audio?');
                $('[data-chat-empty-copy]').text('Choose Live if you’re recording now, or Upload Audio if the file is already on your computer.');
                return;
            }

            $('[data-chat-title]').text(`${project} - ${mode === 'upload' ? 'Upload transcript' : 'Live transcript'}`);
        };

        const conversationStore = new Map();
        let conversationSequence = 0;
        const escapeAttribute = (value) => $('<div>').text(value).html();
        const conversationKey = (name) => String(name || '').trim().toLowerCase();
        const registerConversation = (name, mode = '', options = {}) => {
            const normalized = String(name || '').trim();
            const key = conversationKey(normalized);

            if (!key || normalized === 'No project names yet.') {
                return;
            }

            const existing = conversationStore.get(key) || {
                name: normalized,
                modes: new Set(),
                rank: 0,
            };
            existing.name = existing.name || normalized;
            const nextRank = Number(options.rank || 0);
            if (Number.isFinite(nextRank) && nextRank > existing.rank) {
                existing.rank = nextRank;
            }

            const sourceMode = normalizeMode(mode);
            if (sourceMode) {
                existing.modes.add(sourceMode);
            }

            conversationStore.set(key, existing);
        };
        const preferredConversationMode = (name) => {
            const item = conversationStore.get(conversationKey(name));

            if (!item) {
                return '';
            }

            if (item.modes.has('upload')) {
                return 'upload';
            }

            return item.modes.has('live') ? 'live' : '';
        };
        const collectConversationSources = () => {
            const $buttons = $('[data-category-pick], [data-upload-category-pick]');
            const total = $buttons.length;

            $buttons.each(function (index) {
                const $button = $(this);
                registerConversation(
                    $button.attr('data-category-pick') || $button.attr('data-upload-category-pick') || $button.text(),
                    $button.is('[data-upload-category-pick]') ? 'upload' : 'live',
                    { rank: total - index },
                );
            });
        };
        const renderConversationList = () => {
            const selected = conversationKey(currentProject());
            const conversations = Array.from(conversationStore.values())
                .sort((first, second) => second.rank - first.rank || first.name.localeCompare(second.name));

            $('[data-chat-conversations-empty]').toggleClass('hidden', conversations.length > 0);
            $('[data-chat-local-conversations]').html(conversations.map((conversation) => {
                const isSelected = conversationKey(conversation.name) === selected;
                const sourceMode = preferredConversationMode(conversation.name);

                return `
                    <button
                        type="button"
                        data-chat-project-pick="${escapeAttribute(conversation.name)}"
                        data-chat-source="${sourceMode}"
                        class="flex min-h-11 w-full cursor-pointer items-center rounded-lg px-3 py-2 text-left text-sm leading-5 transition ${isSelected ? 'bg-blue-100 font-semibold text-blue-800 shadow-[inset_3px_0_0_#2563eb]' : 'text-slate-950 hover:bg-blue-50 hover:text-blue-700'}"
                    >
                        <span class="truncate">${escapeAttribute(conversation.name)}</span>
                    </button>
                `;
            }).join(''));
        };

        const setActiveConversationButton = () => {
            collectConversationSources();
            renderConversationList();
        };

        let conversationRefreshTimer = null;
        const refreshConversationSources = () => {
            window.clearTimeout(conversationRefreshTimer);
            conversationRefreshTimer = window.setTimeout(() => {
                $categoryFields.triggerHandler('focus');
                setActiveConversationButton();
            }, 40);
        };

        const syncCommandArea = () => {
            const visible = hasProject()
                && (
                    !$('[data-chat-type-controls]').hasClass('hidden')
                    || !$('[data-chat-controls="live"]').hasClass('hidden')
                    || !$('[data-chat-controls="upload"]').hasClass('hidden')
                    || !$('[data-chat-transcript-actions="live"]').hasClass('hidden')
                    || !$('[data-chat-transcript-actions="upload"]').hasClass('hidden')
                );

            $commandArea.toggleClass('hidden', !visible);
        };

        const clearMode = () => {
            $workspace.attr('data-chat-mode', '');
            $('[data-chat-panel], [data-chat-controls], [data-chat-transcript-actions]')
                .addClass('hidden')
                .removeClass('flex');
            $('[data-chat-panel="empty"]').removeClass('hidden');
            $('[data-chat-mode-button]').removeClass('bg-blue-600 text-white')
                .addClass('border border-blue-200 bg-blue-50 text-blue-700');
            $('[data-chat-empty-controls]').toggleClass('hidden', hasProject()).toggleClass('flex', !hasProject());
            $('[data-chat-type-controls]').toggleClass('hidden', !hasProject()).toggleClass('flex', hasProject());
            updatePendingPanel();
            updateTitle();
            syncCommandArea();
        };

        const transcriptCount = (mode) => {
            const selector = mode === 'upload' ? '[data-upload-transcript-badge]' : '[data-live-transcript-badge]';
            const count = Number(String($(selector).first().text() || '0').replace(/\D+/g, ''));

            return Number.isFinite(count) ? count : 0;
        };

        const existingTranscriptMode = (preferredMode = '') => {
            const normalized = normalizeMode(preferredMode);

            if (normalized && transcriptCount(normalized) > 0) {
                return normalized;
            }

            if (transcriptCount('live') > 0) {
                return 'live';
            }

            return transcriptCount('upload') > 0 ? 'upload' : '';
        };

        const showTranscriptOnly = (mode) => {
            const nextMode = normalizeMode(mode);

            if (!nextMode) {
                clearMode();
                return;
            }

            $workspace.attr('data-chat-mode', nextMode);
            $('[data-chat-panel], [data-chat-controls], [data-chat-type-controls], [data-chat-empty-controls]')
                .addClass('hidden')
                .removeClass('flex');
            $(`[data-chat-panel="${nextMode}"]`).removeClass('hidden');
            updatePendingPanel(nextMode);
            updateTitle();
            syncTranscriptActions(nextMode);
            syncCommandArea();
        };

        const setProject = (name, options = {}) => {
            const normalized = String(name || '').trim();
            const notifyControllers = options.notifyControllers !== false;

            if (!normalized) {
                return false;
            }

            $workspace.attr('data-chat-project', normalized);
            registerConversation(normalized, options.preferredMode, { rank: Number(options.rank || 0) });
            renderConversationList();
            $categoryFields.val(normalized);
            setActiveConversationButton();

            if (notifyControllers) {
                $categoryFields.trigger('input').trigger('change');
            }

            const modeWithTranscript = existingTranscriptMode(options.preferredMode);
            if (modeWithTranscript) {
                showTranscriptOnly(modeWithTranscript);
            } else {
                clearMode();
            }

            return true;
        };

        const setMode = (mode, notifyControllers = true) => {
            const nextMode = normalizeMode(mode);

            if (!nextMode) {
                clearMode();
                return;
            }

            if (!hasProject()) {
                openAddTranscriptModal();
                return;
            }

            $workspace.attr('data-chat-mode', nextMode);
            $('[data-chat-panel], [data-chat-controls]').addClass('hidden').removeClass('flex');
            $(`[data-chat-panel="${nextMode}"]`).removeClass('hidden');
            $(`[data-chat-controls="${nextMode}"]`).removeClass('hidden').addClass('flex');
            $('[data-chat-empty-controls], [data-chat-type-controls]').addClass('hidden').removeClass('flex');
            $('[data-chat-mode-button]').removeClass('bg-blue-600 text-white')
                .addClass('border border-blue-200 bg-blue-50 text-blue-700');
            $(`[data-chat-mode-button="${nextMode}"]`).addClass('bg-blue-600 text-white')
                .removeClass('border border-blue-200 bg-blue-50 text-blue-700');
            updatePendingPanel(nextMode);
            updateTitle();
            syncTranscriptActions(nextMode);
            syncCommandArea();

            if (notifyControllers) {
                $categoryFields.trigger('input').trigger('change');
            }
        };

        const syncTranscriptActions = (mode = currentMode()) => {
            $('[data-chat-transcript-actions]').addClass('hidden').removeClass('flex');

            if (!hasProject() || !mode) {
                return;
            }

            const nextMode = mode === 'upload' ? 'upload' : 'live';
            if (transcriptCount(nextMode) > 0) {
                $(`[data-chat-transcript-actions="${nextMode}"]`).removeClass('hidden').addClass('flex');
            }
            syncCommandArea();
        };

        const syncActivityPanels = () => {
            const isRecording = $('[data-record-toggle]').attr('data-recording') === 'true';
            const liveSupport = String($('[data-audio-support]').text() || '').trim();
            const liveProgress = String($('[data-audio-progress-label]').text() || '').trim();
            const liveActiveName = String($('[data-audio-active-name]').text() || '').trim();
            const showLiveProgress = isRecording
                || !['', 'Ready', 'Choose project'].includes(liveSupport)
                || (liveProgress && liveProgress !== '00:00:00')
                || (liveActiveName && liveActiveName !== 'Ready');

            $('[data-live-progress-panel]').toggleClass('hidden', !showLiveProgress);

            const uploadFileName = String($('[data-upload-file-name]').text() || '').trim();
            const uploadStatus = String($('[data-upload-status]').text() || '').trim();
            const uploadPercent = String($('[data-upload-progress-percent]').text() || '').trim();
            const hasUploadFile = uploadFileName !== '' && uploadFileName !== 'Select an audio file';
            const showUploadProgress = hasUploadFile
                || !['', 'Ready'].includes(uploadStatus)
                || (uploadPercent && uploadPercent !== '0%');

            $('[data-upload-progress-panel]').toggleClass('hidden', !showUploadProgress);
            $('[data-upload-status], [data-upload-progress-percent]').toggleClass('hidden', !showUploadProgress);
        };

        $('[data-chat-mode-button]').on('click', function () {
            setMode(String($(this).attr('data-chat-mode-button') || ''));
        });

        $('[data-chat-change-type]').on('click', () => {
            clearMode();
        });

        $workspace.on('click', '[data-chat-project-pick]', function (event) {
            event.preventDefault();
            const $button = $(this);
            setProject($button.attr('data-chat-project-pick') || $button.text(), {
                preferredMode: normalizeMode($button.attr('data-chat-source')),
            });
        });

        $categoryFields.on('input change', function () {
            const name = String($(this).val() || '').trim();
            if (name && name !== currentProject()) {
                $workspace.attr('data-chat-project', name);
                updateTitle();
                setActiveConversationButton();
            }
        });

        const actionObserver = new MutationObserver(() => {
            setActiveConversationButton();
            syncTranscriptActions();
            syncActivityPanels();
        });
        [
            '[data-live-transcript-badge]',
            '[data-upload-transcript-badge]',
            '[data-stored-list]',
            '[data-upload-transcript-list]',
            '[data-category-suggestions]',
            '[data-upload-category-suggestions]',
        ].forEach((selector) => {
            const target = $(selector).first().get(0);
            if (target) {
                actionObserver.observe(target, { childList: true, subtree: true, characterData: true });
            }
        });

        const conversationSourceObserver = new MutationObserver(() => {
            refreshConversationSources();
            syncTranscriptActions();
            syncActivityPanels();
        });
        [
            '[data-stored-list]',
            '[data-upload-transcript-list]',
        ].forEach((selector) => {
            const target = $(selector).first().get(0);
            if (target) {
                conversationSourceObserver.observe(target, { childList: true, subtree: true });
            }
        });

        const $addTranscriptModal = $('[data-chat-add-modal]');
        const openAddTranscriptModal = () => {
            $addTranscriptModal.removeClass('hidden');
            window.setTimeout(() => {
                $addTranscriptModal.removeClass('opacity-0');
                $('[data-chat-add-name]').trigger('focus');
            }, 0);
        };
        const closeAddTranscriptModal = () => {
            $addTranscriptModal.addClass('opacity-0');
            window.setTimeout(() => $addTranscriptModal.addClass('hidden'), 200);
        };

        $('[data-chat-add-transcript]').on('click', openAddTranscriptModal);
        $('[data-chat-add-close]').on('click', closeAddTranscriptModal);
        $('[data-chat-add-save]').on('click', () => {
            if (setProject($('[data-chat-add-name]').val(), { rank: Date.now() + (++conversationSequence) })) {
                $('[data-chat-add-name]').val('');
                closeAddTranscriptModal();
            }
        });
        $('[data-chat-add-name]').on('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                $('[data-chat-add-save]').trigger('click');
            }
        });
        $addTranscriptModal.on('click', function (event) {
            if (event.target === this) {
                closeAddTranscriptModal();
            }
        });

        const activityObserver = new MutationObserver(syncActivityPanels);
        [
            '[data-record-toggle]',
            '[data-audio-support]',
            '[data-audio-active-name]',
            '[data-audio-progress-label]',
            '[data-upload-file-name]',
            '[data-upload-status]',
            '[data-upload-progress-percent]',
            '[data-upload-queue]',
            '[data-upload-pause]',
            '[data-upload-continue]',
            '[data-upload-retry]',
            '[data-upload-cancel]',
        ].forEach((selector) => {
            const target = $(selector).first().get(0);
            if (target) {
                activityObserver.observe(target, {
                    attributes: true,
                    childList: true,
                    characterData: true,
                    subtree: true,
                });
            }
        });

        const $settingsModal = $('[data-chat-settings-modal]');
        const setSettingsTab = (tab) => {
            const selectedTab = String(tab || 'server');

            $('[data-chat-settings-tab]').each(function () {
                const $tab = $(this);
                const active = String($tab.attr('data-chat-settings-tab') || '') === selectedTab;

                $tab.toggleClass('bg-blue-100 text-blue-800 shadow-[inset_3px_0_0_#2563eb]', active)
                    .toggleClass('text-slate-700 hover:bg-blue-50 hover:text-blue-700', !active);
            });

            $('[data-chat-settings-panel]').addClass('hidden');
            $(`[data-chat-settings-panel="${selectedTab}"]`).removeClass('hidden');
        };

        $('[data-chat-settings-tab]').on('click', function () {
            setSettingsTab($(this).attr('data-chat-settings-tab'));
        });
        $('[data-chat-settings-open]').on('click', () => {
            setSettingsTab($('[data-chat-settings-tab].bg-blue-100').attr('data-chat-settings-tab') || 'server');
            $settingsModal.removeClass('hidden');
            window.setTimeout(() => $settingsModal.removeClass('opacity-0'), 0);
        });
        $('[data-chat-settings-close]').on('click', () => {
            $settingsModal.addClass('opacity-0');
            window.setTimeout(() => $settingsModal.addClass('hidden'), 200);
        });
        $settingsModal.on('click', function (event) {
            if (event.target === this) {
                $('[data-chat-settings-close]').trigger('click');
            }
        });

        window.setTimeout(() => {
            refreshConversationSources();

            const restoredProject = String($categoryFields.first().val() || '').trim();
            if (restoredProject) {
                setProject(restoredProject, { notifyControllers: false });
            } else {
                clearMode();
            }

            setActiveConversationButton();
            syncActivityPanels();
            syncCommandArea();
        }, 0);

        clearMode();
        syncActivityPanels();
        setSettingsTab('server');
        return true;
    };

    try {
        if (initChatWorkspaceTemplate()) {
            initLivePage(context);
            initUploadPage(context);
            initSettingsPage();
            completeDesktopStartup();
            return;
        }

        if ($body.data('page') === 'upload') {
            initUploadPage(context);
            completeDesktopStartup();
            return;
        }

        if ($body.data('page') === 'settings') {
            initSettingsPage();
            completeDesktopStartup();
            return;
        }

        if ($body.data('page') === 'live') {
            initLivePage(context);
        }

        completeDesktopStartup();
    } catch (error) {
        failDesktopStartup(error);
    }
});
