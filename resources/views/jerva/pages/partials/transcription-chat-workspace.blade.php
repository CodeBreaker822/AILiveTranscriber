@props([
    'languageOptions' => [],
    'whisperModels' => [],
    'audioChunkSeconds' => null,
    'apiBaseUrl' => '',
    'hasLicenseKey' => false,
    'licenseKeySuffix' => null,
    'licenseStatusLabel' => 'Not configured',
    'licenseStatusMessage' => '',
    'licenseRefreshError' => null,
    'transcriptionProviders' => [],
    'providerPayload' => [],
    'selectedProvider' => '',
    'selectedModel' => '',
    'resourceProfile' => [],
    'audioMemory' => [],
    'transcriptMemory' => [],
])

<div data-transcription-chat-template data-chat-mode="" data-chat-project="" class="grid h-full min-h-0 grid-cols-[19rem_minmax(0,1fr)] overflow-hidden bg-white text-[14px] leading-5 text-slate-950">
    <aside class="flex min-h-0 flex-col border-r border-slate-200 bg-slate-50">
        <header class="flex h-[72px] shrink-0 items-center justify-between border-b border-slate-200 px-6">
            <div class="flex min-w-0 items-center gap-3">
                <img src="{{ asset(config('app.brand_logo', 'AILogo.png')) }}" alt="{{ config('app.brand_name', 'JERVA Transcriber') }}" class="h-10 w-10 shrink-0 rounded-lg object-contain">
                <div class="min-w-0">
                    <h1 class="truncate text-base font-semibold text-slate-950">{{ config('app.brand_name', 'JERVA Transcriber') }}</h1>
                </div>
            </div>
        </header>

        <div class="shrink-0 border-b border-slate-200 p-3">
            <button type="button" data-chat-add-transcript class="flex h-11 w-full cursor-pointer items-center justify-center rounded-lg bg-blue-600 px-3 text-sm font-semibold text-white transition hover:bg-blue-700">
                Add Transcript
            </button>
        </div>

        <div class="flex min-h-0 flex-1 flex-col">
            <div class="flex shrink-0 items-center justify-between px-4 py-3">
                <p class="text-xs font-semibold uppercase text-slate-500">Recent</p>
            </div>

            <div data-chat-conversation-list class="min-h-0 flex-1 overflow-y-auto px-3 pb-3 pr-2">
                <div data-chat-local-conversations class="space-y-1"></div>
                <p data-chat-conversations-empty class="px-3 py-3 text-sm text-slate-500">No transcripts yet.</p>
            </div>
        </div>

        <footer class="shrink-0 border-t border-blue-100 bg-white p-3">
            <div data-chat-plan-online class="flex items-center justify-between gap-3">
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-black">Free Plan</p>
                    <p class="truncate text-xs font-medium text-blue-900">Sign in when accounts are ready.</p>
                </div>
                <button type="button" data-chat-sign-in class="h-10 shrink-0 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700">
                    Sign in
                </button>
            </div>

            <div data-chat-plan-offline class="hidden">
                <p class="text-sm font-semibold text-black">Free workspace</p>
                <p class="mt-0.5 text-xs font-medium text-blue-900">Offline transcription</p>
            </div>
        </footer>
    </aside>

    <section class="flex min-h-0 flex-col bg-white">
        <header class="flex h-[72px] shrink-0 items-center justify-between border-b border-slate-200 px-6">
            <div class="flex min-w-0 items-center gap-3">
                <label data-whisper-model-control class="hidden min-w-56">
                    <span class="sr-only">Model</span>
                    <select data-whisper-model class="h-10 w-full rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-950 outline-none transition focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                        @foreach ($whisperModels as $model)
                            <option value="{{ $model['id'] }}" @selected($model['id'] === 'turbo')>{{ $model['label'] }} - {{ $model['size'] }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase text-blue-600">Transcript</p>
                    <h2 data-chat-title class="truncate text-lg font-semibold text-slate-950">Choose or add a transcript</h2>
                </div>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <div data-transcription-engine-switch class="flex h-10 items-center gap-2 rounded-lg border border-slate-200 bg-slate-50 px-3" title="Choose online or offline transcription">
                    <span class="text-xs font-semibold text-blue-700">Online</span>
                    <label class="relative inline-flex cursor-pointer items-center">
                        <input type="checkbox" class="peer sr-only" data-transcription-engine-toggle aria-label="Use offline transcription">
                        <span class="h-6 w-11 rounded-full bg-blue-200 transition peer-checked:bg-blue-600 peer-focus-visible:ring-2 peer-focus-visible:ring-blue-200"></span>
                        <span class="absolute left-1 top-1 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                    </label>
                    <span class="text-xs font-semibold text-slate-600">Offline</span>
                </div>

                <button type="button" data-chat-settings-open aria-label="Settings" title="Settings" class="grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-lg border border-slate-200 bg-white text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
                    <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" />
                        <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6V20a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1H4a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6V4a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.88-.34l.06-.06A2 2 0 1 1 19.74 7l-.06.06A1.7 1.7 0 0 0 19.4 9c.22.31.42.64.6 1H20a2 2 0 1 1 0 4h-.09c-.18.36-.38.69-.6 1Z" />
                    </svg>
                </button>
            </div>
        </header>

        <div class="flex min-h-0 flex-1 flex-col">
            <div class="min-h-0 flex-1 overflow-hidden">
                <div data-chat-panel="empty" class="h-full w-full overflow-y-auto px-8 py-6 [scrollbar-gutter:stable]">
                    <div class="mx-auto flex min-h-full max-w-3xl flex-col justify-center py-16">
                        <div class="max-w-2xl">
                            <p class="text-sm font-semibold uppercase text-blue-600">Transcription workspace</p>
                            <h3 data-chat-empty-title class="mt-2 text-3xl font-semibold leading-tight text-black">Hi, what are we transcribing today?</h3>
                            <p data-chat-empty-copy class="mt-4 text-base leading-7 text-blue-950">Start a transcript from the left, then choose Live or Upload Audio. I’ll keep the transcript here so you can polish, summarize, export, or review the processing log when it’s ready.</p>
                        </div>
                    </div>
                </div>

                <div data-chat-panel="live" data-stored-list class="hidden h-full w-full overflow-y-auto px-8 py-6 [scrollbar-gutter:stable]">
                    <div data-stored-empty class="mx-auto flex min-h-full max-w-3xl flex-col justify-center py-16">
                        <div class="max-w-2xl">
                            <p class="text-sm font-semibold uppercase text-blue-600">Live transcript</p>
                            <h3 class="mt-2 text-3xl font-semibold leading-tight text-black">Ready when you are.</h3>
                            <p class="mt-4 text-base leading-7 text-blue-950">Press Live below to start capturing audio. Your transcript will appear here as each section finishes.</p>
                        </div>
                    </div>
                </div>

                <div data-chat-panel="upload" data-upload-transcript-list class="hidden h-full w-full overflow-y-auto px-8 py-6 [scrollbar-gutter:stable]">
                    <div data-upload-transcript-empty class="mx-auto flex min-h-full max-w-3xl flex-col justify-center py-16">
                        <div class="max-w-2xl">
                            <p class="text-sm font-semibold uppercase text-blue-600">Upload transcript</p>
                            <h3 class="mt-2 text-3xl font-semibold leading-tight text-black">Drop in an audio file and I’ll organize the transcript.</h3>
                            <p class="mt-4 text-base leading-7 text-blue-950">Choose Upload Audio below, browse for a file, and the finished transcript will appear here.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div data-chat-command-area class="hidden shrink-0 flex-wrap items-center justify-center gap-3 border-t border-slate-200 bg-white px-6 py-3 flex" data-chat-command-dock>
                <div data-chat-transcript-actions="live" class="order-2 hidden items-center gap-2 rounded-lg border border-blue-100 bg-white p-1.5 shadow-[0_12px_32px_rgba(15,23,42,0.08)]">
                    <button type="button" data-furnish-live class="inline-flex h-11 cursor-pointer items-center rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100">Polish</button>
                    <button type="button" data-summarize="live" class="inline-flex h-11 cursor-pointer items-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">Summarize</button>
                    <select data-export-live-mode class="hidden h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-sm text-slate-700 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                        <option value="raw">Raw</option>
                        <option value="clean">Cleaned</option>
                    </select>
                    <select data-export-live-format class="hidden h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-sm text-slate-700 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                        <option value="txt">TXT</option>
                        <option value="word">Microsoft Word</option>
                        <option value="excel">Excel</option>
                    </select>
                    <div class="relative" data-chat-export-picker="live">
                        <button type="button" data-chat-export-trigger="live" aria-haspopup="menu" aria-expanded="false" title="Export" class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 3v12" />
                                <path d="m7 10 5 5 5-5" />
                                <path d="M5 21h14" />
                            </svg>
                            <span>Export</span>
                        </button>
                        <div data-chat-export-menu="live" role="menu" class="absolute bottom-full right-0 z-20 mb-2 hidden w-44 overflow-hidden rounded-lg border border-blue-100 bg-white p-1 shadow-[0_16px_40px_rgba(15,23,42,0.14)]">
                            <button type="button" data-chat-export-option="live" data-chat-export-format="txt" role="menuitem" class="block h-9 w-full cursor-pointer rounded-md px-3 text-left text-sm font-semibold text-black transition hover:bg-blue-50 hover:text-blue-700">TXT</button>
                            <button type="button" data-chat-export-option="live" data-chat-export-format="word" role="menuitem" class="block h-9 w-full cursor-pointer rounded-md px-3 text-left text-sm font-semibold text-black transition hover:bg-blue-50 hover:text-blue-700">Microsoft Word</button>
                            <button type="button" data-chat-export-option="live" data-chat-export-format="excel" role="menuitem" class="block h-9 w-full cursor-pointer rounded-md px-3 text-left text-sm font-semibold text-black transition hover:bg-blue-50 hover:text-blue-700">Excel</button>
                        </div>
                        <button type="button" data-export-live class="hidden" tabindex="-1" aria-hidden="true"></button>
                    </div>
                    <button type="button" data-log-live aria-label="Processing log" title="Processing log" class="inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M8 6h13" />
                            <path d="M8 12h13" />
                            <path d="M8 18h13" />
                            <path d="M3 6h.01" />
                            <path d="M3 12h.01" />
                            <path d="M3 18h.01" />
                        </svg>
                    </button>
                </div>

                <div data-chat-transcript-actions="upload" class="order-2 hidden items-center gap-2 rounded-lg border border-blue-100 bg-white p-1.5 shadow-[0_12px_32px_rgba(15,23,42,0.08)]">
                    <button type="button" data-furnish-upload class="inline-flex h-11 cursor-pointer items-center rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100">Polish</button>
                    <button type="button" data-summarize="upload" class="inline-flex h-11 cursor-pointer items-center rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">Summarize</button>
                    <select data-export-upload-mode class="hidden h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-sm text-slate-700 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                        <option value="raw">Raw</option>
                        <option value="clean">Cleaned</option>
                    </select>
                    <select data-export-upload-format class="hidden h-9 rounded-lg border border-slate-200 bg-white px-2.5 text-sm text-slate-700 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100">
                        <option value="txt">TXT</option>
                        <option value="word">Microsoft Word</option>
                        <option value="excel">Excel</option>
                    </select>
                    <div class="relative" data-chat-export-picker="upload">
                        <button type="button" data-chat-export-trigger="upload" aria-haspopup="menu" aria-expanded="false" title="Export" class="inline-flex h-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100">
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 3v12" />
                                <path d="m7 10 5 5 5-5" />
                                <path d="M5 21h14" />
                            </svg>
                            <span>Export</span>
                        </button>
                        <div data-chat-export-menu="upload" role="menu" class="absolute bottom-full right-0 z-20 mb-2 hidden w-44 overflow-hidden rounded-lg border border-blue-100 bg-white p-1 shadow-[0_16px_40px_rgba(15,23,42,0.14)]">
                            <button type="button" data-chat-export-option="upload" data-chat-export-format="txt" role="menuitem" class="block h-9 w-full cursor-pointer rounded-md px-3 text-left text-sm font-semibold text-black transition hover:bg-blue-50 hover:text-blue-700">TXT</button>
                            <button type="button" data-chat-export-option="upload" data-chat-export-format="word" role="menuitem" class="block h-9 w-full cursor-pointer rounded-md px-3 text-left text-sm font-semibold text-black transition hover:bg-blue-50 hover:text-blue-700">Microsoft Word</button>
                            <button type="button" data-chat-export-option="upload" data-chat-export-format="excel" role="menuitem" class="block h-9 w-full cursor-pointer rounded-md px-3 text-left text-sm font-semibold text-black transition hover:bg-blue-50 hover:text-blue-700">Excel</button>
                        </div>
                        <button type="button" data-export-upload class="hidden" tabindex="-1" aria-hidden="true"></button>
                    </div>
                    <button type="button" data-log-upload aria-label="Processing log" title="Processing log" class="inline-flex h-11 min-w-11 cursor-pointer items-center justify-center gap-2 rounded-lg border border-slate-200 bg-white px-3 text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M8 6h13" />
                            <path d="M8 12h13" />
                            <path d="M8 18h13" />
                            <path d="M3 6h.01" />
                            <path d="M3 12h.01" />
                            <path d="M3 18h.01" />
                        </svg>
                    </button>
                </div>

                <div data-chat-type-controls class="order-1 mx-auto hidden w-fit max-w-[calc(100%-2rem)] items-center justify-center gap-3 rounded-lg border border-blue-100 bg-white px-3 py-3 shadow-[0_12px_32px_rgba(15,23,42,0.1)]">
                    <button type="button" data-chat-mode-button="live" class="h-12 min-w-40 cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100">Live</button>
                    <button type="button" data-chat-mode-button="upload" class="h-12 min-w-40 cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100">Upload Audio</button>
                </div>

                <div data-chat-controls="live" class="order-1 hidden w-fit max-w-[calc(100%-2rem)] items-center gap-3 rounded-lg border border-blue-100 bg-white px-3 py-3 shadow-[0_12px_32px_rgba(15,23,42,0.1)] transition">
                    <button type="button" data-record-toggle data-recording="false" aria-pressed="false" class="group flex h-12 min-w-40 cursor-pointer items-center justify-center gap-3 rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white outline-none transition hover:bg-blue-700 focus-visible:ring-2 focus-visible:ring-blue-300 disabled:cursor-not-allowed disabled:opacity-60">
                        <span data-record-icon="play">
                            <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                <path d="M8 5.14v13.72c0 .84.92 1.35 1.63.91l10.72-6.86a1.1 1.1 0 0 0 0-1.86L9.63 4.23A1.08 1.08 0 0 0 8 5.14Z" />
                            </svg>
                        </span>
                        <span data-record-icon="stop" class="hidden">
                            <svg viewBox="0 0 24 24" class="h-5 w-5 fill-current" aria-hidden="true">
                                <rect x="6.5" y="6.5" width="11" height="11" rx="2" />
                            </svg>
                        </span>
                        <span>
                            <span data-record-state class="block text-xs font-semibold uppercase">Listening</span>
                            <span data-record-caption class="block text-sm font-semibold">Ready to capture</span>
                        </span>
                    </button>
                    <button type="button" data-open-sidebar="pending" aria-expanded="false" class="h-12 min-w-32 cursor-pointer rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50">Pending clips</button>
                    <div data-live-progress-panel class="hidden w-80 min-w-0 flex-none">
                        <div class="flex min-w-0 items-center gap-2 text-sm">
                            <span data-audio-active-name class="shrink-0 font-semibold text-slate-950">Ready</span>
                            <span data-audio-active-note class="min-w-0 truncate text-slate-500"></span>
                            <span data-audio-progress-label class="ml-auto shrink-0 font-semibold text-blue-700">00:00:00</span>
                        </div>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div data-audio-progress class="h-full w-0 rounded-full bg-blue-600 transition-[width] duration-150"></div>
                        </div>
                        <p data-audio-support class="mt-1 text-xs font-medium text-slate-500">Ready</p>
                    </div>
                </div>

                <form data-chat-controls="upload" data-upload-form class="order-1 hidden w-fit max-w-[calc(100%-2rem)] flex-wrap items-center justify-center gap-3 rounded-lg border border-blue-100 bg-white px-3 py-3 shadow-[0_12px_32px_rgba(15,23,42,0.1)] transition" action="#" method="post" enctype="multipart/form-data">
                    <label for="chat_audio_file" class="inline-flex h-12 min-w-32 shrink-0 cursor-pointer items-center justify-center rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold text-blue-700 transition hover:border-blue-300 hover:bg-blue-100">
                        Browse
                        <input id="chat_audio_file" name="audio_file" type="file" accept="audio/*" class="sr-only" data-upload-file>
                    </label>
                    <div data-upload-progress-panel class="hidden w-80 min-w-0 flex-none">
                        <p data-upload-file-name class="truncate text-sm font-semibold text-slate-950">Select an audio file</p>
                        <p data-upload-file-meta class="truncate text-xs text-slate-500">WAV, MP3, M4A, AAC, OGG, FLAC.</p>
                        <p class="text-xs text-slate-500">Duration: <span data-upload-duration class="font-semibold text-slate-700">--:--</span></p>
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div data-upload-progress class="h-full w-0 rounded-full bg-blue-600 transition-[width] duration-150"></div>
                        </div>
                    </div>
                    <span data-upload-status class="hidden max-w-28 truncate text-xs font-semibold text-slate-600">Ready</span>
                    <span data-upload-progress-percent class="hidden w-10 text-right text-xs font-semibold text-blue-700">0%</span>
                    <button type="button" data-upload-queue disabled class="h-12 min-w-20 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:bg-slate-200 disabled:text-slate-500">Start</button>
                    <button type="button" data-upload-pause disabled class="h-12 min-w-20 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50">Pause</button>
                    <button type="button" data-upload-continue disabled class="h-12 min-w-24 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50">Continue</button>
                    <button type="button" data-upload-retry disabled class="h-12 min-w-20 cursor-pointer rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-50">Retry</button>
                    <button type="button" data-upload-cancel disabled class="h-12 min-w-20 cursor-pointer rounded-lg border border-red-200 bg-red-50 px-3 text-sm font-semibold text-red-700 transition hover:border-red-300 hover:bg-red-100 disabled:cursor-not-allowed disabled:opacity-50">Cancel</button>
                    <button type="button" data-open-sidebar="pending" aria-expanded="false" class="h-12 min-w-32 cursor-pointer rounded-lg border border-slate-200 bg-white px-4 text-sm font-semibold text-slate-700 transition hover:border-blue-300 hover:bg-blue-50">Pending clips</button>
                </form>
            </div>
        </div>
    </section>

    <div class="hidden">
        <input type="text" name="category_name" data-category-input data-upload-category autocomplete="off">
        <div data-chat-source-hooks>
            <div data-category-suggestions></div>
            <div data-upload-category-suggestions></div>
        </div>
        <input type="hidden" name="chunk_seconds" value="{{ $audioChunkSeconds }}" data-upload-chunk-size>
        <button type="button" data-live-continue disabled>Continue</button>
        <button type="button" data-live-retry disabled>Retry</button>
        <button type="button" data-live-cancel disabled>Cancel</button>
        <span data-live-cleaner-state>Waiting</span>
        <span data-live-cleaner-progress-label>0 / 0 sections</span>
        <span data-live-cleaner-progress-percent>0%</span>
        <span data-live-cleaner-progress-bar></span>
        <span data-live-cleaner-progress-note></span>
        <span data-upload-sherpa-progress class="hidden"></span>
        <span data-upload-sherpa-status>Waiting</span>
        <span data-upload-sherpa-percent>0%</span>
        <span data-upload-sherpa-bar></span>
        <span data-cleaner-state>Waiting</span>
        <span data-cleaner-progress-label>0 / 0 sections</span>
        <span data-cleaner-progress-percent>0%</span>
        <span data-cleaner-progress-bar></span>
        <span data-cleaner-progress-note></span>
        <span data-current-category></span>
        <span data-audio-count>0</span>
        <span data-live-transcript-badge>0</span>
        <span data-upload-transcript-badge>0</span>

        <label data-language-control>
            <select data-language-input>
                @foreach ($languageOptions as $option)
                    <option value="{{ $option['value'] }}" @selected($loop->first)>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </label>

        <label data-language-control>
            <select data-upload-language name="language_code">
                @foreach ($languageOptions as $option)
                    <option value="{{ $option['value'] }}" @selected($loop->first)>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </label>

        <input type="checkbox" data-use-vad checked>
        <input type="checkbox" data-use-diarization checked>
    </div>

    <div data-chat-add-modal class="fixed inset-0 z-50 hidden bg-slate-950/40 p-6 opacity-0 transition-opacity duration-200">
        <div class="mx-auto mt-24 w-full max-w-md rounded-lg bg-white p-4 shadow-2xl">
            <header class="flex items-center justify-between">
                <h2 class="text-base font-semibold text-slate-950">Add Transcript</h2>
                <button type="button" data-chat-add-close aria-label="Close" class="grid h-8 w-8 cursor-pointer place-items-center rounded-lg text-slate-600 hover:bg-slate-50">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="m6 6 12 12" />
                        <path d="m18 6-12 12" />
                    </svg>
                </button>
            </header>
            <label class="mt-4 block">
                <span class="text-sm font-medium text-slate-700">Transcript name</span>
                <input type="text" data-chat-add-name class="mt-2 h-11 w-full rounded-lg border border-slate-200 px-3 text-sm text-slate-950 outline-none focus:border-blue-400 focus:ring-2 focus:ring-blue-100" placeholder="Project or conversation name">
            </label>
            <div class="mt-4 flex justify-end gap-2">
                <button type="button" data-chat-add-close class="h-10 cursor-pointer rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                <button type="button" data-chat-add-save class="h-10 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white hover:bg-blue-700">Add</button>
            </div>
        </div>
    </div>

    @include('jerva.pages.partials.workspace-settings-modal', [
        'apiBaseUrl' => $apiBaseUrl,
        'hasLicenseKey' => $hasLicenseKey,
        'licenseKeySuffix' => $licenseKeySuffix,
        'licenseStatusLabel' => $licenseStatusLabel,
        'licenseStatusMessage' => $licenseStatusMessage,
        'licenseRefreshError' => $licenseRefreshError,
        'transcriptionProviders' => $transcriptionProviders,
        'providerPayload' => $providerPayload,
        'selectedProvider' => $selectedProvider,
        'selectedModel' => $selectedModel,
        'resourceProfile' => $resourceProfile,
        'audioMemory' => $audioMemory,
        'transcriptMemory' => $transcriptMemory,
    ])
</div>
