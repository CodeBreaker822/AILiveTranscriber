$(function () {
    const dialog = document.querySelector('[data-app-update-dialog]');

    if (!(dialog instanceof HTMLDialogElement) || window.__aiTranscriberUpdateCheckStarted) {
        return;
    }

    window.__aiTranscriberUpdateCheckStarted = true;

    const $dialog = $(dialog);
    const $title = $dialog.find('[data-app-update-title]');
    const $message = $dialog.find('[data-app-update-message]');
    const $notes = $dialog.find('[data-app-update-notes]');
    const $label = $dialog.find('[data-app-update-progress-label]');
    const $percent = $dialog.find('[data-app-update-progress-percent]');
    const $bar = $dialog.find('[data-app-update-progress-bar]');
    const $actions = $dialog.find('[data-app-update-actions]');
    const $retry = $dialog.find('[data-app-update-retry]');
    const connectivityUrl = String(document.body.dataset.updateConnectivityUrl || '');
    const statusUrl = String(document.body.dataset.updateStatusUrl || '');
    const downloadUrl = String(document.body.dataset.updateDownloadUrl || '');
    const desktopDev = document.body.dataset.desktopDev === 'true';
    let updateStatus = null;
    let running = false;

    const setProgress = (value, label) => {
        const progress = Math.max(0, Math.min(100, Math.round(value)));
        $bar.css('width', `${progress}%`);
        $percent.text(`${progress}%`);
        $label.text(label);
    };

    const showModal = () => {
        $dialog.removeClass('hidden');

        if (!dialog.open) {
            dialog.showModal();
        }
    };

    const showError = (error) => {
        const message = String(error?.message || error || '').trim();
        $title.text('Update paused');
        $message.text(message || 'The update could not be completed. Check the connection and try again.');
        $label.text('Download failed');
        $actions.removeClass('hidden').addClass('flex');
        running = false;
    };

    const responseMessage = async (response, fallback) => {
        try {
            const payload = await response.json();
            return String(payload?.message || fallback);
        } catch (_error) {
            return fallback;
        }
    };

    const installDownloadedUpdate = async (archivePath) => {
        const invoke = window.__TAURI__?.core?.invoke;

        setProgress(100, 'Installing');
        $title.text('Restarting AITranscriber');
        $message.text('The update is downloaded. AITranscriber will close, install it, and reopen.');

        if (typeof invoke !== 'function') {
            $message.text('The update was downloaded locally. Open the desktop application to install it.');
            return;
        }

        await invoke('install_update', { archivePath });
    };

    const downloadUpdate = async () => {
        if (running || !downloadUrl) {
            return;
        }

        running = true;
        $actions.addClass('hidden').removeClass('flex');
        $title.text('Updating');
        $message.text(`Downloading AITranscriber ${updateStatus.version}…`);
        setProgress(0, 'Connecting');

        try {
            const response = await fetch(downloadUrl, {
                method: 'GET',
                cache: 'no-store',
                headers: { Accept: 'application/zip' },
            });

            if (!response.ok || !response.body) {
                throw new Error(await responseMessage(response, 'The update ZIP could not be downloaded.'));
            }

            const archivePath = String(response.headers.get('X-Update-Archive-Path') || '');
            const total = Number(response.headers.get('Content-Length') || 0);
            const reader = response.body.getReader();
            let received = 0;
            let indeterminateProgress = 4;

            while (true) {
                const { done, value } = await reader.read();

                if (done) {
                    break;
                }

                received += value?.byteLength || 0;

                if (total > 0) {
                    setProgress((received / total) * 100, 'Downloading');
                } else {
                    indeterminateProgress = Math.min(92, indeterminateProgress + 2);
                    setProgress(indeterminateProgress, 'Downloading');
                }
            }

            if (!archivePath) {
                throw new Error('The local update archive path was not returned.');
            }

            await installDownloadedUpdate(archivePath);
        } catch (error) {
            showError(error);
        }
    };

    const checkForUpdate = async () => {
        if (!connectivityUrl || !statusUrl || !downloadUrl || navigator.onLine === false) {
            return;
        }

        try {
            const connectivityResponse = await fetch(connectivityUrl, {
                method: 'GET',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });

            if (!connectivityResponse.ok) {
                return;
            }

            const connectivity = await connectivityResponse.json();

            if (connectivity?.online !== true) {
                return;
            }

            const response = await fetch(statusUrl, {
                method: 'GET',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) {
                return;
            }

            const payload = await response.json();

            if (!payload?.available || !String(payload.version || '').trim()) {
                return;
            }

            updateStatus = payload;
            const notes = String(payload.notes || '').trim();

            if (notes) {
                $notes.text(notes).removeClass('hidden');
            }

            showModal();
            await downloadUpdate();
        } catch (_error) {
            // A background check should not interrupt transcription when the server is unavailable.
        }
    };

    $retry.on('click', downloadUpdate);
    if (!desktopDev) {
        checkForUpdate();
    }
});
