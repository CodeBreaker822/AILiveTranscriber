@props(['activePage' => 'live'])

@php
    $workspace = $activePage === 'workspace';
@endphp

<dialog data-summary-dialog data-workspace-theme="{{ $workspace ? 'true' : 'false' }}" style="z-index: 2147483000;" class="fixed inset-0 z-[80] m-auto hidden max-h-[calc(100dvh-2rem)] w-[min(94vw,52rem)] overflow-hidden rounded-lg p-0 shadow-2xl {{ $workspace ? 'border border-blue-200 bg-white text-black backdrop:bg-blue-950/30' : 'border border-white/10 bg-slate-950 text-slate-100 backdrop:bg-slate-950/85' }}">
    @if ($workspace)
        <section class="flex max-h-[calc(100dvh-2rem)] min-h-0 flex-col" role="document">
            <header class="flex min-h-16 shrink-0 flex-wrap items-center justify-between gap-3 border-b border-blue-100 px-5 py-3">
                <div class="min-w-0 flex-1">
                    <p class="text-xs font-semibold uppercase text-blue-600">Summary</p>
                    <h2 class="truncate text-base font-semibold text-black" data-summary-project>Choose project</h2>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <select data-summary-export-format class="min-h-10 rounded-lg border border-blue-200 bg-white px-3 py-2 text-xs font-semibold uppercase text-blue-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
                        <option value="txt">TXT</option>
                        <option value="excel">Excel</option>
                        <option value="word">Microsoft Word</option>
                    </select>
                    <button type="button" data-summary-export disabled class="min-h-10 shrink-0 cursor-pointer rounded-lg border border-blue-200 bg-white px-4 py-2 text-sm font-semibold text-blue-900 transition hover:bg-blue-50 disabled:cursor-not-allowed disabled:opacity-60">
                        Export
                    </button>
                    <button type="button" data-summary-close class="grid h-10 w-10 shrink-0 cursor-pointer place-items-center rounded-lg border border-blue-200 bg-white text-blue-900 transition hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700" aria-label="Close summary">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="m6 6 12 12" />
                            <path d="m18 6-12 12" />
                        </svg>
                    </button>
                </div>
            </header>

            <div class="flex shrink-0 flex-wrap items-center gap-2 border-b border-blue-100 px-5 py-3">
                <label class="flex min-h-10 items-center gap-2 rounded-lg border border-blue-200 bg-white px-3 text-sm font-semibold text-black">
                    Source
                    <select data-summary-source class="h-8 cursor-pointer rounded-md border border-blue-200 bg-white px-2 text-sm font-semibold text-blue-900 outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-100">
                        <option value="raw">Raw transcript</option>
                        <option value="cleaned">Cleaned transcript</option>
                    </select>
                </label>
                <span data-summary-status class="inline-flex min-h-10 items-center rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-800">Ready</span>
            </div>

            <div data-summary-progress class="hidden h-1 shrink-0 overflow-hidden bg-blue-100">
                <div class="h-full w-full animate-pulse bg-blue-600"></div>
            </div>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4 [scrollbar-color:#2563eb_#dbeafe] [scrollbar-width:thin]">
                <div data-summary-text class="mx-auto max-w-3xl break-words text-sm leading-7 text-black">No summary has been created for this project.</div>
                <p data-summary-error class="mx-auto mt-3 hidden max-w-3xl border-l-2 border-blue-500 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-800"></p>
            </div>

            <footer class="flex shrink-0 flex-wrap items-center justify-between gap-3 border-t border-blue-100 px-5 py-4">
                <p class="text-xs text-blue-900">Starting again replaces this project's existing summary.</p>
                <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                    <button type="button" data-summary-run class="min-h-10 shrink-0 cursor-pointer rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-60">
                        Summarize
                    </button>
                </div>
            </footer>
        </section>
    @else
        <section class="flex max-h-[calc(100dvh-2rem)] min-h-0 flex-col p-4 sm:p-5" role="document">
            <header class="flex shrink-0 flex-wrap items-start justify-between gap-4">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-violet-300">Project summary</p>
                    <h2 class="mt-1 truncate text-lg font-semibold text-white" data-summary-project>Choose project</h2>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <select data-summary-export-format class="min-h-9 rounded-lg border border-white/10 bg-slate-900 px-3 py-2 text-xs font-semibold uppercase tracking-[0.12em] text-white outline-none focus:border-violet-300/40 focus:ring-2 focus:ring-violet-300/20">
                        <option value="txt">TXT</option>
                        <option value="excel">Excel</option>
                        <option value="word">Microsoft Word</option>
                    </select>
                    <button type="button" data-summary-export disabled class="min-h-9 shrink-0 cursor-pointer rounded-lg border border-white/10 bg-white/[0.04] px-4 py-2 text-sm font-semibold text-white transition hover:border-violet-300/30 hover:bg-violet-300/10 disabled:cursor-not-allowed disabled:opacity-60">
                        Export
                    </button>
                    <button type="button" data-summary-close class="grid h-9 w-9 shrink-0 cursor-pointer place-items-center rounded-lg border border-white/10 bg-white/[0.04] text-slate-300 transition hover:bg-white/10 hover:text-white" aria-label="Close summary">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="m6 6 12 12" />
                            <path d="m18 6-12 12" />
                        </svg>
                    </button>
                </div>
            </header>

            <label class="mt-3 shrink-0 text-xs font-semibold text-slate-300">
                Summarize
                <select data-summary-source class="mt-1.5 w-full cursor-pointer rounded-lg border border-white/10 bg-slate-900 px-3 py-2 text-sm text-white outline-none focus:border-violet-300/40 focus:ring-2 focus:ring-violet-300/20">
                    <option value="raw">Raw transcript</option>
                    <option value="cleaned">Cleaned transcript</option>
                </select>
            </label>

            <div class="mt-3 flex shrink-0 items-center justify-between gap-3 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-xs">
                <span data-summary-status class="font-semibold text-slate-300">Ready</span>
            </div>
            <div data-summary-progress class="mt-2 hidden h-1.5 shrink-0 overflow-hidden rounded-full bg-white/10">
                <div class="h-full w-full animate-pulse rounded-full bg-violet-300"></div>
            </div>

            <div data-summary-text class="mt-3 min-h-40 flex-1 overflow-y-auto break-words rounded-lg border border-white/10 bg-slate-900/80 p-4 text-sm leading-6 text-slate-200">No summary has been created for this project.</div>
            <p data-summary-error class="mt-2 hidden shrink-0 text-sm text-rose-300"></p>

            <footer class="mt-3 flex shrink-0 flex-wrap items-center justify-between gap-3">
                <p class="text-xs text-slate-500">Starting again replaces this project's existing summary.</p>
                <div class="flex shrink-0 flex-wrap items-center justify-end gap-2">
                    <button type="button" data-summary-run class="min-h-9 shrink-0 cursor-pointer rounded-lg bg-violet-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-violet-200 disabled:cursor-not-allowed disabled:opacity-60">
                        Summarize
                    </button>
                </div>
            </footer>
        </section>
    @endif
</dialog>
