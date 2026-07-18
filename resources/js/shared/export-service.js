// Thin download bridge. Export file content (txt/xls/doc) is generated
// server-side by TranscriptExportController; this module only fetches the
// payload and writes it to disk (desktop save dialog) or the browser.

import { escapeHtml } from './dom.js';

export const browserDownloadFile = (filename, content, mimeType = 'text/plain;charset=utf-8') => {
    const body = /application\/(vnd\.ms-excel|msword)/i.test(mimeType)
        ? `﻿${content}`
        : content;
    const blob = new Blob([body], { type: mimeType });
    const url = URL.createObjectURL(blob);
    const $link = $('<a>')
        .attr('href', url)
        .attr('download', filename)
        .appendTo('body');
    $link.get(0)?.click();
    window.setTimeout(() => {
        $link.remove();
        URL.revokeObjectURL(url);
    }, 1000);

    if (typeof window.showNotification === 'function') {
        window.showNotification(`Export download started: ${filename}`, 'success');
    }
};

export const saveTranscriptExport = async ({
    filename,
    content,
    mimeType = 'text/plain;charset=utf-8',
    extension = 'txt',
    filterName = 'Text files',
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

        if (path && typeof window.showNotification === 'function') {
            window.showNotification(`Export saved to ${path}`, 'success');
        }
    } catch (error) {
        if (extension !== 'txt') {
            browserDownloadFile(filename, content, mimeType);
            return;
        }

        try {
            const path = await invoke('save_text_export_with_dialog', { content });

            if (path && typeof window.showNotification === 'function') {
                window.showNotification(`Export saved to ${path}`, 'success');
            }
        } catch (fallbackError) {
            if (typeof window.showNotification === 'function') {
                const message = String(fallbackError || error || '').trim();
                window.showNotification(message || 'Could not save the export. Please try again.', 'error');
            }
        }
    }
};

export const exportTranscriptRows = async ({ exportUrl, categoryName, mode, format }) => {
    const csrfToken = String($('meta[name="csrf-token"]').attr('content') || '');
    let payload = {};

    try {
        payload = await $.ajax({
            url: String(exportUrl || ''),
            method: 'POST',
            dataType: 'json',
            contentType: 'application/json',
            headers: {
                'X-CSRF-TOKEN': csrfToken,
                'X-Requested-With': 'XMLHttpRequest',
            },
            data: JSON.stringify({
                category_name: categoryName,
                mode,
                format,
            }),
        });
    } catch (error) {
        throw new Error(String(error?.responseJSON?.message || 'No transcription is ready to export yet.'));
    }
    const filename = String(payload?.filename || 'transcription.txt');
    const mimeType = String(payload?.mime_type || 'text/plain;charset=utf-8');
    const content = String(payload?.content || '');
    const extension = filename.split('.').pop() || 'txt';

    await saveTranscriptExport({
        filename,
        content,
        mimeType,
        extension,
        filterName: extension === 'txt' ? 'Text files' : (extension === 'xls' ? 'Excel files' : 'Word documents'),
    });
};

export const exportRowsToText = () => '';
