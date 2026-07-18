$(function () {
    const $body = $('body');
    const $downloadButton = $('[data-offline-model-download]');
    const $engineSwitch = $('[data-transcription-engine-switch]');
    const $engineToggle = $('[data-transcription-engine-toggle]');
    const $dialog = $('[data-offline-model-dialog]');
    const $expanded = $('[data-offline-model-expanded]');
    const $compact = $('[data-offline-model-compact]');
    const $backdrop = $('[data-offline-model-backdrop]');
    const $minimizeButton = $('[data-offline-model-minimize]');
    const $closeButton = $('[data-offline-model-close]');
    const $retryButton = $('[data-offline-model-retry]');
    const $actions = $('[data-offline-model-actions]');
    const $cancelActions = $('[data-offline-model-cancel-actions]');
    const $cancelButton = $('[data-offline-model-cancel]');
    const $progress = $('[data-offline-model-progress]');
    const $downloadNote = $('[data-offline-model-download-note]');
    const $title = $('[data-offline-model-title]');
    const $message = $('[data-offline-model-message]');
    const $compactLabel = $('[data-offline-model-compact-label]');
    const $progressLabels = $('[data-offline-model-progress-label]');
    const $progressPercents = $('[data-offline-model-progress-percent]');
    const $progressBars = $('[data-offline-model-progress-bar]');
    const statusUrl = String($body.attr('data-offline-model-status-url') || '');
    const downloadUrl = String($body.attr('data-offline-model-download-url') || '');
    const csrfToken = String($('meta[name="csrf-token"]').attr('content') || '');
    const appBrandName = String($body.attr('data-app-brand-name') || 'the app').trim();

    if (!$downloadButton.length || !$engineSwitch.length || !$dialog.length || !$expanded.length || !statusUrl || !downloadUrl) {
        return;
    }

    let currentStatus = { installed: false, default_model: 'turbo', models: [] };
    let activeModel = 'turbo';
    let downloading = false;
    let downloadRequest = null;
    let downloadRun = 0;
    let $modelList = $dialog.find('[data-offline-model-list]');

    if (!$modelList.length) {
        $modelList = $('<div>')
            .attr('data-offline-model-list', '')
            .addClass('mt-3 min-h-0 overflow-y-auto rounded-lg border border-white/10 bg-white/[0.02]')
            .insertAfter($message);
    }

    const setVisible = ($element, visible, displayClass = '') => {
        $element.prop('hidden', !visible)
            .toggleClass('hidden', !visible);

        if (displayClass) {
            $element.toggleClass(displayClass, visible);
        }
    };
    const installedModels = (status) => (Array.isArray(status?.models) ? status.models : [])
        .filter((model) => model?.kind !== 'diarization' && model?.installed === true && model?.supported !== false);
    const dispatchStatus = (status) => {
        $(window).trigger('offline-model:status', [status]);
    };
    const syncHeader = (status) => {
        const hasModel = installedModels(status).length > 0;

        setVisible($downloadButton, !hasModel, 'inline-flex');
        setVisible($engineSwitch, hasModel, 'flex');
        $engineToggle.prop('disabled', !hasModel);
        $downloadButton.find('[data-offline-model-label]').text('Download Offline');

        if (!hasModel && $engineToggle.length) {
            $engineToggle.prop('checked', false).trigger('change');
        }
    };
    const updateProgress = (percent, label = 'Downloading') => {
        const normalized = Math.max(0, Math.min(100, Number(percent) || 0));

        $progressLabels.text(label);
        $progressPercents.text(`${Math.round(normalized)}%`);
        $progressBars.css('width', `${normalized}%`);
    };
    const renderModels = () => {
        const models = (Array.isArray(currentStatus.models) ? currentStatus.models : [])
            .filter((model) => model?.kind !== 'diarization');

        $modelList.empty();

        models.forEach((model) => {
            const installed = model.installed === true;
            const supported = model.supported !== false;
            const memory = Number(model.runtime_memory_mb || 0);
            const unsupportedReason = String(model.unsupported_reason || '');
            const label = String(model.label || model.id || 'Local model');
            const detail = supported
                ? `${String(model.size || 'Offline model')}${memory > 0 ? ` Â· ~${memory} MB RAM` : ''}`
                : unsupportedReason || `${String(model.size || 'Offline model')} Â· exceeds safe RAM budget`;
            const buttonLabel = installed ? 'Installed' : supported ? 'Download' : unsupportedReason ? 'Unavailable' : 'Low memory';
            const buttonClasses = installed
                ? 'border-emerald-300/20 bg-emerald-300/10 text-emerald-200'
                : supported
                    ? 'border-cyan-300/30 bg-cyan-300 text-slate-950 hover:bg-cyan-200'
                    : 'border-white/10 bg-white/[0.03] text-slate-500';
            const $option = $(`
                <div data-offline-model-option="${String(model.id || '')}" class="flex min-w-0 items-center justify-between gap-4 border-b border-white/10 px-3 py-2.5 transition last:border-b-0 hover:bg-white/[0.03]">
                    <span class="min-w-0">
                        <span class="block truncate text-sm font-semibold text-white"></span>
                        <span class="mt-0.5 block text-xs text-slate-400"></span>
                    </span>
                    <button type="button" class="min-w-[5.5rem] shrink-0 cursor-pointer rounded-md border px-3 py-1.5 text-xs font-semibold transition disabled:cursor-default ${buttonClasses}"></button>
                </div>
            `);

            $option.find('span span:first-child').text(label);
            $option.find('span span:nth-child(2)').text(detail);
            $option.find('button')
                .text(buttonLabel)
                .prop('disabled', downloading || installed || !supported)
                .attr('aria-label', `${buttonLabel} ${label}`)
                .on('click', () => startDownload(String(model.id || 'turbo')));
            $modelList.append($option);
        });

        if (!models.length) {
            $('<p>')
                .addClass('rounded-lg border border-amber-300/20 bg-amber-300/10 px-4 py-3 text-sm text-amber-100')
                .text('The offline model catalog could not be loaded. Please try again.')
                .appendTo($modelList);
        }
    };
    const showDialog = () => {
        $dialog.prop('hidden', false)
            .removeClass('hidden')
            .addClass('pointer-events-none')
            .attr('aria-hidden', 'false');
        setVisible($backdrop, true);
        setVisible($expanded, true);
        setVisible($compact, false, 'flex');
    };
    const hideDialog = () => {
        if (downloading) {
            setVisible($backdrop, false);
            setVisible($expanded, false);
            setVisible($compact, true, 'flex');
            return;
        }

        $dialog.prop('hidden', true)
            .addClass('hidden pointer-events-none')
            .attr('aria-hidden', 'true');
    };
    const showCatalog = () => {
        downloading = false;
        $title.text('Download Offline Whisper');
        $message.text(`Choose a Whisper model for offline transcription. Speaker Separation is already included with ${appBrandName}.`);
        $compactLabel.text('Local model download');
        setVisible($modelList, true);
        setVisible($actions, false, 'flex');
        setVisible($cancelActions, false, 'flex');
        setVisible($progress, false);
        setVisible($downloadNote, false);
        renderModels();
        showDialog();
    };
    const normalizeStatus = (status = {}) => ({
        installed: status?.installed === true,
        default_model: String(status?.default_model || 'turbo'),
        models: Array.isArray(status?.models) ? status.models : [],
        diarization_installed: status?.diarization_installed === true,
    });
    const refreshStatus = async ({ safeFallback = false } = {}) => {
        try {
            const status = await $.ajax({
                url: statusUrl,
                method: 'GET',
                dataType: 'json',
                cache: false,
                headers: { Accept: 'application/json' },
            });

            currentStatus = normalizeStatus(status);
            activeModel = activeModel || currentStatus.default_model;
            syncHeader(currentStatus);
            renderModels();
            dispatchStatus(currentStatus);

            return currentStatus;
        } catch (error) {
            if (safeFallback) {
                currentStatus = { installed: false, default_model: 'turbo', models: [] };
                dispatchStatus(currentStatus);
            }

            throw error;
        }
    };
    const handleDownloadEvent = (event) => {
        if (event.type === 'progress') {
            const total = Number(event.total_bytes || 0);
            const received = Number(event.received_bytes || 0);
            updateProgress(total > 0 ? (received / total) * 100 : 0, 'Downloading');
            return;
        }

        if (event.type === 'source') {
            $message.text(`Downloading ${String(event.asset || activeModel)} from ${String(event.host || 'the model server')}.`);
            return;
        }

        if (event.type === 'error') {
            throw new Error(String(event.message || 'Offline model download failed.'));
        }

        if (event.type === 'complete') {
            updateProgress(100, 'Installed');
        }
    };
    const processNdjsonBuffer = (state, done = false) => {
        const lines = state.buffer.split(/\r?\n/);
        state.buffer = done ? '' : (lines.pop() || '');

        lines.forEach((line) => {
            if (line.trim()) {
                handleDownloadEvent(JSON.parse(line));
            }
        });

        if (done && state.buffer.trim()) {
            handleDownloadEvent(JSON.parse(state.buffer));
        }
    };

    async function startDownload(model) {
        if (downloading) {
            return;
        }

        activeModel = model || currentStatus.default_model || 'turbo';
        downloading = true;
        const run = ++downloadRun;
        const streamState = { processedLength: 0, buffer: '' };

        $title.text(`Installing ${activeModel}`);
        $message.text('Connecting to the offline model server.');
        $compactLabel.text(`Installing ${activeModel}`);
        setVisible($modelList, false);
        setVisible($actions, false, 'flex');
        setVisible($cancelActions, true, 'flex');
        setVisible($progress, true);
        setVisible($downloadNote, true);
        updateProgress(0, 'Connecting');
        renderModels();
        showDialog();

        try {
            const responseText = await $.ajax({
                url: downloadUrl,
                method: 'POST',
                dataType: 'text',
                headers: {
                    Accept: 'application/x-ndjson, application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                data: { model: activeModel },
                xhr: () => {
                    const xhr = $.ajaxSettings.xhr();
                    xhr.onprogress = () => {
                        const chunk = String(xhr.responseText || '').slice(streamState.processedLength);
                        streamState.processedLength = String(xhr.responseText || '').length;
                        streamState.buffer += chunk;
                        processNdjsonBuffer(streamState, false);
                    };

                    return xhr;
                },
                beforeSend: (xhr) => {
                    downloadRequest = xhr;
                },
            });

            if (run !== downloadRun) {
                return;
            }

            const trimmed = String(responseText || '').trim();
            if (trimmed.startsWith('{')) {
                currentStatus = normalizeStatus(JSON.parse(trimmed));
            } else {
                processNdjsonBuffer(streamState, true);
            }

            downloading = false;
            downloadRequest = null;
            await refreshStatus();
            $title.text('Local model installed');
            $message.text('Offline transcription is ready. You can choose the installed Whisper model from the transcription controls.');
            setVisible($actions, true, 'flex');
            setVisible($cancelActions, false, 'flex');
            $retryButton.addClass('hidden');
            $(window).trigger('offline-model:installed', [currentStatus]);
        } catch (error) {
            if (run !== downloadRun || String(error?.statusText || '') === 'abort') {
                return;
            }

            downloading = false;
            downloadRequest = null;
            $title.text('Download failed');
            $message.text(String(error?.responseJSON?.message || error?.message || 'Offline model download failed. Please try again.'));
            setVisible($actions, true, 'flex');
            setVisible($cancelActions, false, 'flex');
            $retryButton.removeClass('hidden');
            renderModels();
        }
    }

    const cancelDownload = () => {
        if (!downloading) {
            return;
        }

        downloadRun += 1;
        downloading = false;
        downloadRequest?.abort?.();
        downloadRequest = null;
        $title.text('Download canceled');
        $message.text('The offline model download was canceled. You can retry whenever you are ready.');
        $compactLabel.text('Download canceled');
        updateProgress(0, 'Canceled');
        setVisible($cancelActions, false, 'flex');
        setVisible($actions, true, 'flex');
        $retryButton.removeClass('hidden');
        renderModels();
        showDialog();
    };
    const openCatalog = async (model = '') => {
        if (downloading) {
            showDialog();
            return;
        }

        if (model) {
            activeModel = model;
        }

        try {
            await refreshStatus();
        } catch {
            // The catalog renders a safe retry state below.
        }
        showCatalog();
    };

    $downloadButton.on('click', () => openCatalog());
    $minimizeButton.on('click', hideDialog);
    $compact.on('click', showDialog);
    $closeButton.on('click', hideDialog);
    $backdrop.on('click', hideDialog);
    $retryButton.on('click', () => startDownload(activeModel));
    $cancelButton.on('click', cancelDownload);
    $(window).on('offline-model:catalog-request', (event, payload = {}) => {
        const model = String(payload?.model || currentStatus.default_model || 'turbo');
        openCatalog(model);
    });

    refreshStatus({ safeFallback: true }).catch(() => {});
});
