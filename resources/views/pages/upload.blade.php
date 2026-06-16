<x-app-layout title="Upload Audio | AI Transcriber" active-page="upload">
    <div class="flex flex-col gap-5">
        <div class="grid gap-5 lg:grid-cols-[1.02fr_0.98fr]">
            <section class="rounded-lg border border-white/10 bg-slate-950/70 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl lg:p-7">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Recorded audio</p>
                        <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">Upload transcription</h1>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Upload a long recording once, then process it in one-minute sections for steady progress and cleaner retries.</p>
                    </div>
                    <span class="rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-3 py-1 text-xs font-semibold uppercase tracking-[0.24em] text-emerald-100">Long file</span>
                </div>

                <form class="mt-6 space-y-5" action="#" method="post" enctype="multipart/form-data">
                    <div class="rounded-lg border border-dashed border-cyan-300/25 bg-cyan-300/5 p-6">
                        <label for="audio_file" class="flex min-h-[16rem] cursor-pointer flex-col items-center justify-center gap-4 text-center">
                            <span class="grid h-16 w-16 place-items-center rounded-lg border border-cyan-300/20 bg-slate-950/70 text-cyan-200">
                                <svg viewBox="0 0 24 24" class="h-7 w-7" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M12 3v12" />
                                    <path d="m7 8 5-5 5 5" />
                                    <path d="M5 21h14" />
                                    <path d="M5 17h14" />
                                </svg>
                            </span>
                            <span class="max-w-md">
                                <span data-upload-file-name class="block text-xl font-semibold text-white">Select an audio file</span>
                                <span data-upload-file-meta class="mt-2 block text-sm leading-6 text-slate-400">WAV, MP3, M4A, AAC, OGG, FLAC, and other audio files.</span>
                            </span>
                            <input id="audio_file" name="audio_file" type="file" accept="audio/*" class="sr-only" data-upload-file>
                            <span class="inline-flex items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-4 py-2 text-sm font-medium text-white">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M4 7h16" />
                                    <path d="M4 12h16" />
                                    <path d="M4 17h10" />
                                </svg>
                                Browse file
                            </span>
                        </label>
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="relative">
                            <label class="block">
                                <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Category</span>
                                <input type="text" name="category_name" data-upload-category placeholder="Choose or type a category" autocomplete="off" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white placeholder:text-slate-500 outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                            </label>
                            <div data-upload-category-suggestions class="absolute left-0 right-0 top-full z-20 mt-2 hidden max-h-48 overflow-y-auto rounded-lg border border-white/10 bg-slate-950/95 p-2 shadow-[0_20px_60px_rgba(0,0,0,0.35)] backdrop-blur-xl"></div>
                        </div>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Model</span>
                            <select name="model_id" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                                <option value="scribe_v2">Scribe v2</option>
                                <option value="scribe_v1">Scribe v1</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Language</span>
                            <select name="language_code" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                                <option value="">Auto detect</option>
                                <option value="eng">English</option>
                                <option value="fil">Filipino</option>
                                <option value="zho">Chinese</option>
                            </select>
                        </label>

                        <label class="block">
                            <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Chunk length</span>
                            <select name="chunk_seconds" class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20" data-upload-chunk-size>
                                <option value="60">1 minute</option>
                                <option value="120">2 minutes</option>
                                <option value="300">5 minutes</option>
                            </select>
                        </label>
                    </div>

                    <div class="grid gap-3 md:grid-cols-3">
                        <div class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                            <p class="text-xs uppercase tracking-[0.24em] text-slate-400">Duration</p>
                            <p data-upload-duration class="mt-2 text-xl font-semibold text-white">--:--</p>
                        </div>
                        <div class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                            <p class="text-xs uppercase tracking-[0.24em] text-slate-400">Sections</p>
                            <p data-upload-sections class="mt-2 text-xl font-semibold text-white">0</p>
                        </div>
                        <div class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                            <p class="text-xs uppercase tracking-[0.24em] text-slate-400">Status</p>
                            <p data-upload-status class="mt-2 text-xl font-semibold text-white">Ready</p>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-white/10 bg-white/[0.03] p-4">
                        <div>
                            <p class="text-sm font-medium text-white">One-minute processing</p>
                            <p class="mt-1 text-sm text-slate-400">The recording is prepared as smaller sections before transcription begins.</p>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" data-upload-queue class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200 disabled:cursor-not-allowed disabled:bg-slate-600 disabled:text-slate-300">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="m5 12 5 5L20 7" />
                                </svg>
                                Start
                            </button>
                            <button type="button" data-upload-continue class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-4 py-2 text-sm font-semibold text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10 disabled:cursor-not-allowed disabled:opacity-50">
                                Continue
                            </button>
                            <button type="button" data-upload-retry class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-amber-300/20 bg-amber-300/10 px-4 py-2 text-sm font-semibold text-amber-100 transition hover:border-amber-300/30 hover:bg-amber-300/15 disabled:cursor-not-allowed disabled:opacity-50">
                                Retry
                            </button>
                            <button type="button" data-upload-cancel class="inline-flex min-h-11 cursor-pointer items-center gap-2 rounded-lg border border-rose-400/20 bg-rose-400/10 px-4 py-2 text-sm font-semibold text-rose-100 transition hover:border-rose-400/30 hover:bg-rose-400/15 disabled:cursor-not-allowed disabled:opacity-50">
                                Cancel
                            </button>
                        </div>
                    </div>
                </form>
            </section>

            <aside class="flex min-h-0 flex-col gap-5">
                <section class="flex h-full min-h-0 flex-1 flex-col rounded-lg border border-white/10 bg-slate-950/70 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl lg:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Upload queue</p>
                            <h2 class="mt-2 text-2xl font-semibold tracking-tight text-white">Pending clips</h2>
                        </div>
                        <span data-upload-active-count class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.26em] text-slate-400">0 parked</span>
                    </div>

                    <div class="mt-5 rounded-lg border border-white/10 bg-white/[0.03] p-4">
                        <div class="flex flex-wrap items-center justify-between gap-4">
                            <div>
                                <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Now</p>
                                <p data-upload-status class="mt-2 text-lg font-semibold text-white">Ready</p>
                                <p class="mt-1 text-sm text-slate-400">No audio yet</p>
                                <p data-upload-progress-label class="mt-1 text-xs uppercase tracking-[0.24em] text-cyan-300">0 / 0 sections</p>
                            </div>
                            <div class="text-right">
                                <p data-upload-progress-percent class="mt-2 text-lg font-semibold text-white">0%</p>
                            </div>
                        </div>
                        <div class="mt-4 h-3 overflow-hidden rounded-full bg-slate-800/80">
                            <div data-upload-progress class="h-full w-0 rounded-full bg-gradient-to-r from-cyan-400 via-emerald-300 to-amber-300 transition-[width] duration-150"></div>
                        </div>
                    </div>

                    <div data-upload-queue-list class="mt-5 max-h-[28rem] min-h-0 flex-1 space-y-3 overflow-y-auto pr-2">
                        <div data-upload-empty class="rounded-lg border border-dashed border-cyan-300/20 bg-cyan-300/5 p-4">
                            <p class="text-sm text-slate-200">No pending recordings yet.</p>
                        </div>
                    </div>
                </section>

                <section class="rounded-lg border border-white/10 bg-slate-950/70 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl lg:p-6">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Cleaner</p>
                            <h2 class="mt-2 text-2xl font-semibold tracking-tight text-white">Cleaner progress</h2>
                        </div>
                        <span data-cleaner-state class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.24em] text-slate-400">Waiting</span>
                    </div>

                    <div class="mt-5 rounded-lg border border-white/10 bg-white/[0.03] p-4">
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.3em] text-slate-400">Progress</p>
                                <p data-cleaner-progress-label class="mt-2 text-xl font-semibold text-white">0 / 0 batches</p>
                            </div>
                            <p data-cleaner-progress-percent class="text-lg font-semibold text-cyan-200">0%</p>
                        </div>
                        <div class="mt-4 h-3 overflow-hidden rounded-full bg-slate-800/80">
                            <div data-cleaner-progress-bar class="h-full w-0 rounded-full bg-cyan-300 transition-all duration-300"></div>
                        </div>
                        <p data-cleaner-progress-note class="mt-4 text-sm leading-6 text-slate-400">Cleaned transcript will be prepared in one-minute batches after raw transcription is ready.</p>
                    </div>
                </section>
            </aside>
        </div>

        <section class="rounded-lg border border-white/10 bg-slate-950/70 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl lg:p-6">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3">
                    <h2 class="text-2xl font-semibold tracking-tight text-white">Transcription</h2>
                    <span data-upload-transcript-category class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.26em] text-slate-400">
                        Upload audio
                    </span>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" data-furnish-upload class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.18em] text-emerald-100 transition hover:border-emerald-300/30 hover:bg-emerald-300/15">
                        Furnish Transcript
                    </button>
                    <select data-export-upload-mode class="min-h-9 rounded-lg border border-white/10 bg-slate-950/80 px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.18em] text-white outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20">
                        <option value="raw">Raw</option>
                        <option value="clean">Cleaned</option>
                    </select>
                    <button type="button" data-export-upload class="inline-flex min-h-9 cursor-pointer items-center gap-2 rounded-lg border border-white/10 bg-white/[0.04] px-3 py-1.5 text-xs font-semibold uppercase tracking-[0.18em] text-white transition hover:border-cyan-300/30 hover:bg-cyan-300/10">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M12 3v12" />
                            <path d="m7 10 5 5 5-5" />
                            <path d="M5 21h14" />
                        </svg>
                        Export
                    </button>
                    <div class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.26em] text-slate-400">
                        <span data-upload-transcript-count>0</span> sections
                    </div>
                </div>
            </div>

            <div data-upload-transcript-list class="mt-5 max-h-[38vh] w-full overflow-y-auto pr-2">
                <div data-upload-transcript-empty class="w-full py-4">
                    <p class="text-sm text-slate-200">Transcript will appear here as each section finishes.</p>
                </div>
            </div>
        </section>
    </div>
</x-app-layout>
