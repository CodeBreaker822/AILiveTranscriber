(() => {
    'use strict';

    const ready = (callback) => document.readyState === 'loading'
        ? document.addEventListener('DOMContentLoaded', callback, { once: true })
        : callback();

    ready(() => {
        const dialog = document.querySelector('[data-summary-dialog]');
        const statusUrl = String(document.body.dataset.summaryStatusUrl || '');
        const storeUrl = String(document.body.dataset.summaryStoreUrl || '');

        if (!(dialog instanceof HTMLDialogElement) || !statusUrl || !storeUrl) {
            return;
        }

        const project = dialog.querySelector('[data-summary-project]');
        const status = dialog.querySelector('[data-summary-status]');
        const model = dialog.querySelector('[data-summary-model]');
        const text = dialog.querySelector('[data-summary-text]');
        const error = dialog.querySelector('[data-summary-error]');
        const progress = dialog.querySelector('[data-summary-progress]');
        const runButton = dialog.querySelector('[data-summary-run]');
        const closeButton = dialog.querySelector('[data-summary-close]');
        const source = dialog.querySelector('[data-summary-source]');
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        let categoryName = '';
        let pollTimer = null;
        let requestRunning = false;

        const selectedCategory = (source) => String(document.querySelector(
            source === 'live' ? '[data-category-input]' : '[data-upload-category]',
        )?.value || '').trim();

        const setPolling = (enabled) => {
            if (pollTimer) {
                window.clearInterval(pollTimer);
                pollTimer = null;
            }
            if (enabled && dialog.open) {
                pollTimer = window.setInterval(() => loadSummary().catch(() => {}), 2000);
            }
        };

        const render = (data = {}) => {
            const state = String(data.status || 'idle');
            const summary = typeof data.summary_text === 'string' ? data.summary_text : '';
            const message = String(data.error_message || '');
            const processing = state === 'processing';

            if (data.source_type === 'raw' || data.source_type === 'cleaned') {
                source.value = data.source_type;
            }

            status.textContent = processing ? 'Summarizing…' : state === 'complete' ? 'Complete' : state === 'failed' ? 'Failed' : 'Ready';
            model.textContent = [data.provider, data.model].filter(Boolean).join(' · ');
            text.textContent = summary || (processing
                ? 'The summary is being prepared. You may close this window and return later.'
                : 'No summary has been created for this project.');
            error.textContent = message;
            error.classList.toggle('hidden', !message);
            progress.classList.toggle('hidden', !processing);
            runButton.disabled = requestRunning;
            runButton.textContent = state === 'complete' || processing ? 'Replace summary' : state === 'failed' ? 'Retry' : 'Summarize';
            setPolling(processing && !requestRunning);
        };

        async function loadSummary() {
            const url = new URL(statusUrl, window.location.origin);
            url.searchParams.set('category_name', categoryName);
            const response = await fetch(url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) {
                throw new Error('Summary status could not be loaded.');
            }
            const payload = await response.json();
            render(payload?.data || {});
        }

        const runSummary = async () => {
            if (requestRunning || !categoryName) {
                return;
            }

            requestRunning = true;
            render({ status: 'processing' });

            try {
                const response = await fetch(storeUrl, {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        category_name: categoryName,
                        source_type: String(source.value || 'raw'),
                    }),
                });
                const payload = await response.json().catch(() => ({}));
                if (!response.ok) {
                    throw new Error(String(payload.message || 'The transcript could not be summarized.'));
                }
                render(payload.data || {});
            } catch (exception) {
                render({ status: 'failed', error_message: String(exception?.message || exception) });
            } finally {
                requestRunning = false;
                runButton.disabled = false;
            }
        };

        document.addEventListener('click', async (event) => {
            const button = event.target.closest('[data-summarize]');
            if (!button) {
                return;
            }

            categoryName = selectedCategory(String(button.dataset.summarize || ''));
            if (!categoryName) {
                window.showNotification?.('Choose a Project Name before summarizing.', 'error');
                return;
            }

            project.textContent = categoryName;
            source.value = 'raw';
            dialog.classList.remove('hidden');
            if (!dialog.open) {
                dialog.showModal();
            }
            render({ status: 'idle' });
            loadSummary().catch((exception) => render({ status: 'failed', error_message: exception.message }));
        });

        runButton.addEventListener('click', runSummary);
        closeButton.addEventListener('click', () => dialog.close());
        dialog.addEventListener('close', () => {
            dialog.classList.add('hidden');
            setPolling(false);
        });
    });
})();
