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
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    window.setTimeout(() => {
        link.remove();
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
    const csrfToken = String(document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '');

    const response = await fetch(String(exportUrl || ''), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
            category_name: categoryName,
            mode,
            format,
        }),
    });

    if (!response.ok) {
        let message = 'No transcription is ready to export yet.';
        try {
            const payload = await response.json();
            if (payload?.message) {
                message = payload.message;
            }
        } catch (error) {
            // keep default message
        }
        throw new Error(message);
    }

    const payload = await response.json();
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
