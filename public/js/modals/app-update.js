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
    const desktopDev = document.body.dataset.desktopDev === 'true';
    let updateStatus = null;
    let running = false;
    let unlistenProgress = null;

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

    const cleanupProgressListener = async () => {
        if (typeof unlistenProgress !== 'function') {
            return;
        }

        const release = unlistenProgress;
        unlistenProgress = null;

        try {
            release();
        } catch (_error) {
            // The app may already be closing for installation.
        }
    };

    const listenForProgress = async () => {
        await cleanupProgressListener();

        const listen = window.__TAURI__?.event?.listen;

        if (typeof listen !== 'function') {
            return;
        }

        unlistenProgress = await listen('app-update-progress', (event) => {
            const payload = event?.payload || {};
            const status = String(payload.status || '');
            const percent = Number(payload.percent || 0);

            if (status === 'downloaded') {
                setProgress(100, 'Installing');
                $title.text('Restarting AITranscriber');
                $message.text('The update is verified. AITranscriber will close, install it, and reopen.');
                return;
            }

            if (percent > 0) {
                setProgress(percent, 'Downloading');
                return;
            }

            const downloaded = Number(payload.downloaded || 0);
            const progress = Math.min(92, Math.max(4, Math.round(downloaded / 1024 / 1024)));
            setProgress(progress, 'Downloading');
        });
    };

    const downloadUpdate = async () => {
        const invoke = window.__TAURI__?.core?.invoke;

        if (running || typeof invoke !== 'function') {
            return;
        }

        running = true;
        $actions.addClass('hidden').removeClass('flex');
        $title.text('Updating');
        $message.text(`Downloading AITranscriber ${updateStatus.version}...`);
        setProgress(0, 'Connecting');

        try {
            await listenForProgress();
            await invoke('install_update');
        } catch (error) {
            await cleanupProgressListener();
            showError(error);
        }
    };

    const checkForUpdate = async () => {
        const invoke = window.__TAURI__?.core?.invoke;

        if (typeof invoke !== 'function' || navigator.onLine === false) {
            return;
        }

        try {
            const payload = await invoke('check_app_update');

            if (!payload?.available || !String(payload.version || '').trim()) {
                return;
            }

            updateStatus = payload;
            const notes = String(payload.notes || payload.body || '').trim();

            if (notes) {
                $notes.text(notes).removeClass('hidden');
            }

            showModal();
            await downloadUpdate();
        } catch (_error) {
            // A background update check should not interrupt transcription.
        }
    };

    $retry.on('click', downloadUpdate);
    if (!desktopDev) {
        checkForUpdate();
    }
});
