$(function () {
    const $dialog = $('[data-summary-dialog]');
    const dialog = $dialog.get(0);
    const statusUrl = String($('body').attr('data-summary-status-url') || '');
    const storeUrl = String($('body').attr('data-summary-store-url') || '');

    if (!(dialog instanceof HTMLDialogElement) || !statusUrl || !storeUrl) {
        return;
    }

    const $project = $dialog.find('[data-summary-project]');
    const $status = $dialog.find('[data-summary-status]');
    const $text = $dialog.find('[data-summary-text]');
    const $error = $dialog.find('[data-summary-error]');
    const $progress = $dialog.find('[data-summary-progress]');
    const $runButton = $dialog.find('[data-summary-run]');
    const $exportButton = $dialog.find('[data-summary-export]');
    const $exportFormat = $dialog.find('[data-summary-export-format]');
    const $closeButton = $dialog.find('[data-summary-close]');
    const $source = $dialog.find('[data-summary-source]');
    const workspaceTheme = String($dialog.attr('data-workspace-theme') || 'false') === 'true';
    const csrfToken = String($('meta[name="csrf-token"]').attr('content') || '');
    const backgroundJobs = window.AITranscriberBackgroundJobs;
    let categoryName = '';
    let pollTimer = null;
    let requestRunning = false;
    let currentSummary = {};

    const escapeHtml = (value) => $('<div>').text(String(value || '')).html();
    const renderInlineMarkdown = (value) => escapeHtml(value)
        .replace(/\*\*(.+?)\*\*/g, `<strong class="font-semibold ${workspaceTheme ? 'text-black' : 'text-white'}">$1</strong>`);
    const closeList = (html, listOpen) => {
        if (listOpen) {
            html.push('</ul>');
        }

        return false;
    };
    const renderSummaryMarkdown = (value) => {
        const html = [];
        let listOpen = false;

        String(value || '').replace(/\r\n?/g, '\n').split('\n').forEach((line) => {
            const trimmed = String(line || '').trim();

            if (!trimmed) {
                listOpen = closeList(html, listOpen);
                return;
            }

            const heading = trimmed.match(/^(#{1,6})\s+(.+)$/);
            if (heading) {
                listOpen = closeList(html, listOpen);
                const level = Math.min(4, Math.max(3, heading[1].length));
                const headingClass = workspaceTheme
                    ? 'mt-5 first:mt-0 text-sm font-semibold uppercase text-blue-700'
                    : 'mt-4 first:mt-0 text-sm font-semibold uppercase tracking-[0.12em] text-violet-200';
                html.push(`<h${level} class="${headingClass}">${renderInlineMarkdown(heading[2])}</h${level}>`);
                return;
            }

            const bullet = trimmed.match(/^[-*]\s+(.+)$/);
            if (bullet) {
                if (!listOpen) {
                    html.push(`<ul class="${workspaceTheme ? 'my-3 ml-5 list-disc space-y-2' : 'my-2 ml-5 list-disc space-y-1'}">`);
                    listOpen = true;
                }
                html.push(`<li>${renderInlineMarkdown(bullet[1])}</li>`);
                return;
            }

            listOpen = closeList(html, listOpen);
            html.push(`<p class="${workspaceTheme ? 'my-3 first:mt-0 last:mb-0' : 'my-2 first:mt-0 last:mb-0'}">${renderInlineMarkdown(trimmed)}</p>`);
        });

        closeList(html, listOpen);

        return html.join('') || `<p class="${workspaceTheme ? 'text-blue-900' : 'text-slate-400'}">No summary has been created for this project.</p>`;
    };
    const renderSummaryExportMarkdown = (value) => {
        const html = [];
        let listOpen = false;
        const closeExportList = () => {
            if (listOpen) {
                html.push('</ul>');
                listOpen = false;
            }
        };

        String(value || '').replace(/\r\n?/g, '\n').split('\n').forEach((line) => {
            const trimmed = String(line || '').trim();

            if (!trimmed) {
                closeExportList();
                return;
            }

            const heading = trimmed.match(/^(#{1,6})\s+(.+)$/);
            if (heading) {
                closeExportList();
                const level = heading[1].length <= 3 ? 2 : 3;
                html.push(`<h${level}>${renderInlineMarkdown(heading[2])}</h${level}>`);
                return;
            }

            const bullet = trimmed.match(/^[-*]\s+(.+)$/);
            if (bullet) {
                if (!listOpen) {
                    html.push('<ul>');
                    listOpen = true;
                }
                html.push(`<li>${renderInlineMarkdown(bullet[1])}</li>`);
                return;
            }

            closeExportList();
            html.push(`<p>${renderInlineMarkdown(trimmed)}</p>`);
        });

        closeExportList();

        return html.join('') || '<p>No summary has been created for this project.</p>';
    };
    const markdownToPlainText = (value) => String(value || '')
        .replace(/\r\n?/g, '\n')
        .split('\n')
        .map((line) => line
            .replace(/^\s{0,3}#{1,6}\s+/, '')
            .replace(/^\s*[-*]\s+/, '- ')
            .replace(/\*\*(.+?)\*\*/g, '$1')
            .trimEnd())
        .join('\n')
        .replace(/\n{3,}/g, '\n\n')
        .trim();
    const slugify = (value) => String(value || 'summary')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '') || 'summary';
    const browserDownloadFile = (filename, content, mimeType = 'text/plain;charset=utf-8') => {
        const body = /application\/(vnd\.ms-excel|msword)/i.test(mimeType) ? `\ufeff${content}` : content;
        const url = URL.createObjectURL(new Blob([body], { type: mimeType }));
        const $link = $('<a>').attr({ href: url, download: filename }).appendTo('body');

        $link.get(0)?.click();
        window.setTimeout(() => {
            $link.remove();
            URL.revokeObjectURL(url);
        }, 1000);
        window.showNotification?.(`Export download started: ${filename}`, 'success');
    };
    const saveSummaryExport = async ({
        filename,
        content,
        extension = 'txt',
        filterName = 'Text files',
        mimeType = 'text/plain;charset=utf-8',
    }) => {
        const invoke = window.__TAURI__?.core?.invoke;

        if (typeof invoke !== 'function') {
            browserDownloadFile(filename, content, mimeType);
            return;
        }

        try {
            const path = await invoke('save_text_export_with_dialog', {
                content,
                filename,
                defaultExtension: extension,
                filterName,
                filterExtensions: [extension],
            });

            if (path) {
                window.showNotification?.(`Export saved to ${path}`, 'success');
            }
        } catch (exception) {
            if (extension !== 'txt') {
                browserDownloadFile(filename, content, mimeType);
                return;
            }

            window.showNotification?.(
                String(exception || '').trim() || 'Could not save the summary export. Please try again.',
                'error',
            );
        }
    };
    const summaryText = () => String(currentSummary.summary_text || '').trim();
    const summaryPlainText = () => markdownToPlainText(summaryText());
    const summaryReadyForExport = () => String(currentSummary.status || '') === 'complete' && summaryText() !== '';
    const summaryExportTitle = () => `${categoryName || 'Project'} - Summary`;
    const summarySourceLabel = () => String(currentSummary.source_type || $source.val() || 'raw') === 'cleaned'
        ? 'Cleaned transcript'
        : 'Raw transcript';
    const summaryMeta = () => [
        `Project: ${categoryName || 'Untitled project'}`,
        `Source: ${summarySourceLabel()}`,
    ].filter(Boolean);
    const buildSummaryTextExport = () => [
        summaryExportTitle(),
        ...summaryMeta(),
        '',
        summaryPlainText(),
    ].join('\n');
    const buildSummarySpreadsheetExport = () => `<!doctype html>
<html><head><meta charset="utf-8"><style>
body { font-family: Calibri, Arial, sans-serif; color: #0f172a; }
h1 { color: #7c3aed; font-size: 22px; margin: 0 0 12px; }
h2 { color: #312e81; font-size: 16px; margin: 12px 0 6px; border-bottom: 1px solid #ddd6fe; padding-bottom: 3px; }
h3 { color: #4338ca; font-size: 13px; margin: 10px 0 4px; }
p { margin: 0 0 7px; } ul { margin: 4px 0 10px 22px; padding: 0; } li { margin: 0 0 4px; }
strong { color: #111827; font-weight: 700; } table { border-collapse: collapse; width: 100%; }
th { width: 9rem; background: #312e81; color: #ffffff; font-weight: 700; padding: 9px; text-align: left; border: 1px solid #4338ca; }
td { padding: 9px; vertical-align: top; border: 1px solid #cbd5e1; line-height: 1.35; } tr:nth-child(even) td { background: #f8fafc; }
.summary-cell { background: #ffffff; }
</style></head><body><h1>${escapeHtml(summaryExportTitle())}</h1><table><tbody>
<tr><th>Project</th><td>${escapeHtml(categoryName || 'Untitled project')}</td></tr>
<tr><th>Source</th><td>${escapeHtml(summarySourceLabel())}</td></tr>
<tr><th>Summary</th><td class="summary-cell">${renderSummaryExportMarkdown(summaryText())}</td></tr>
</tbody></table></body></html>`;
    const buildSummaryWordExport = () => `<!doctype html>
<html><head><meta charset="utf-8"><style>
body { font-family: Calibri, Arial, sans-serif; color: #111827; line-height: 1.55; }
.page { max-width: 760px; margin: 0 auto; } h1 { margin: 0 0 6px; color: #312e81; font-size: 26px; }
.meta { margin: 0 0 18px; color: #64748b; font-size: 12px; text-transform: uppercase; letter-spacing: 1.6px; }
.summary { border-top: 2px solid #ddd6fe; padding-top: 14px; }
h2 { margin: 16px 0 8px; color: #312e81; font-size: 18px; border-bottom: 1px solid #ddd6fe; padding-bottom: 4px; }
h3 { margin: 12px 0 6px; color: #4338ca; font-size: 15px; } p { margin: 0 0 9px; }
ul { margin: 4px 0 12px 24px; padding: 0; } li { margin: 0 0 5px; } strong { color: #111827; font-weight: 700; }
</style></head><body><div class="page"><h1>${escapeHtml(summaryExportTitle())}</h1>
<p class="meta">${escapeHtml(summaryMeta().join(' | '))}</p><div class="summary">${renderSummaryExportMarkdown(summaryText())}</div></div></body></html>`;
    const selectedCategory = (source) => String($(
        source === 'live' ? '[data-category-input]' : '[data-upload-category]',
    ).first().val() || '').trim();
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
        currentSummary = data || {};
        const state = String(data.status || 'idle');
        const summary = typeof data.summary_text === 'string' ? data.summary_text : '';
        const message = String(data.error_message || '');
        const processing = state === 'processing';

        if (data.source_type === 'raw' || data.source_type === 'cleaned') {
            $source.val(data.source_type);
        }

        $status.text(processing ? 'Summarizing...' : state === 'complete' ? 'Complete' : state === 'failed' ? 'Failed' : 'Ready');
        $text[summary ? 'html' : 'text'](summary
            ? renderSummaryMarkdown(summary)
            : processing
                ? 'The summary is being prepared. You may close this window and return later.'
                : 'No summary has been created for this project.');
        $error.text(message).toggleClass('hidden', !message);
        $progress.toggleClass('hidden', !processing);
        $runButton.prop('disabled', requestRunning)
            .text(state === 'complete' || processing ? 'Replace summary' : state === 'failed' ? 'Retry' : 'Summarize');
        $exportButton.prop('disabled', !summaryReadyForExport());
        setPolling(processing && !requestRunning);
    };

    async function loadSummary() {
        const payload = await $.ajax({
            url: statusUrl,
            method: 'GET',
            dataType: 'json',
            cache: false,
            headers: { Accept: 'application/json' },
            data: { category_name: categoryName },
        });

        render(payload?.data || {});
    }

    const runSummary = async () => {
        if (requestRunning || !categoryName) {
            return;
        }

        requestRunning = true;
        render({ status: 'processing' });

        try {
            const payload = await backgroundJobs.request({
                url: storeUrl,
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                data: JSON.stringify({
                    category_name: categoryName,
                    source_type: String($source.val() || 'raw'),
                }),
                contentType: 'application/json',
            }, {
                timeoutMs: 600000,
                intervalMs: 1200,
                failureMessage: 'The transcript could not be summarized.',
                cancelledMessage: 'Transcript summarization was cancelled.',
            });
            requestRunning = false;
            render(payload.data || {});
        } catch (exception) {
            const message = String(exception?.responseJSON?.message || exception?.message || exception || 'The transcript could not be summarized.');
            requestRunning = false;
            render({ status: 'failed', error_message: message });
        } finally {
            requestRunning = false;
            $runButton.prop('disabled', false);
        }
    };
    const exportSummary = async () => {
        if (!summaryReadyForExport()) {
            window.showNotification?.('Create a summary before exporting.', 'error');
            return;
        }

        const format = String($exportFormat.val() || 'txt');
        const filenameBase = `${slugify(categoryName)}-summary`;

        if (typeof window.toggleLoading === 'function') {
            window.toggleLoading($exportButton, true);
        }

        try {
            if (format === 'excel') {
                await saveSummaryExport({
                    filename: `${filenameBase}.xls`,
                    content: buildSummarySpreadsheetExport(),
                    extension: 'xls',
                    filterName: 'Excel files',
                    mimeType: 'application/vnd.ms-excel;charset=utf-8',
                });
                return;
            }

            if (format === 'word') {
                await saveSummaryExport({
                    filename: `${filenameBase}.doc`,
                    content: buildSummaryWordExport(),
                    extension: 'doc',
                    filterName: 'Word documents',
                    mimeType: 'application/msword;charset=utf-8',
                });
                return;
            }

            await saveSummaryExport({
                filename: `${filenameBase}.txt`,
                content: buildSummaryTextExport(),
                extension: 'txt',
                filterName: 'Text files',
                mimeType: 'text/plain;charset=utf-8',
            });
        } finally {
            if (typeof window.toggleLoading === 'function') {
                window.toggleLoading($exportButton, false);
            }
        }
    };

    $(document).on('click', '[data-summarize]', function () {
        categoryName = selectedCategory(String($(this).attr('data-summarize') || ''));
        if (!categoryName) {
            window.showNotification?.('Choose a Project Name before summarizing.', 'error');
            return;
        }

        $project.text(categoryName);
        $source.val('raw');
        $dialog.removeClass('hidden');
        if (!dialog.open) {
            dialog.showModal();
        }
        render({ status: 'idle' });
        loadSummary().catch((exception) => render({ status: 'failed', error_message: exception.message }));
    });
    $runButton.on('click', runSummary);
    $exportButton.on('click', exportSummary);
    $closeButton.on('click', () => dialog.close());
    $dialog.on('close', () => {
        $dialog.addClass('hidden');
        setPolling(false);
    });
});
