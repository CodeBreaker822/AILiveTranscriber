@props(['activePage' => 'live'])

@php
    $workspace = $activePage === 'workspace';
@endphp

<dialog data-polish-dialog data-workspace-theme="{{ $workspace ? 'true' : 'false' }}" style="z-index: 2147483000;" class="fixed inset-0 m-auto hidden max-h-[calc(100dvh-2rem)] w-[min(92vw,42rem)] overflow-hidden rounded-lg p-0 shadow-2xl {{ $workspace ? 'border border-blue-200 bg-white text-black backdrop:bg-blue-950/30' : 'border border-white/10 bg-slate-950 text-slate-100 backdrop:bg-slate-950/80' }}">
    @if ($workspace)
        <form method="dialog" class="flex max-h-[calc(100dvh-2rem)] min-h-0 flex-col">
            <header class="flex h-16 shrink-0 items-center justify-between border-b border-blue-100 px-5">
                <div class="min-w-0">
                    <p class="text-xs font-semibold uppercase text-blue-600">Polish transcript</p>
                    <h2 class="truncate text-base font-semibold text-black">Instructions</h2>
                </div>
                <button type="submit" value="cancel" class="grid h-9 w-9 cursor-pointer place-items-center rounded-lg border border-blue-200 bg-white text-blue-900 transition hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700" aria-label="Close polish instructions">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="m6 6 12 12" />
                        <path d="m18 6-12 12" />
                    </svg>
                </button>
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4 [scrollbar-color:#2563eb_#dbeafe] [scrollbar-width:thin]">
                <label class="block" for="polish-instructions">
                    <span class="text-sm font-semibold text-black">Preset</span>
                    <span class="mt-2 grid gap-2 sm:grid-cols-2" aria-label="Instruction presets">
                        @foreach ([
                            'translate-en' => 'Translate to English',
                            'translate-fil' => 'Translate to Filipino',
                            'fix-grammar' => 'Fix grammar',
                            'translate-en-fix-grammar' => 'Translate and fix',
                        ] as $preset => $label)
                            <button type="button" data-polish-preset="{{ $preset }}" aria-pressed="false" class="min-h-10 cursor-pointer rounded-lg border border-blue-200 bg-white px-3 py-2 text-left text-sm font-semibold text-blue-900 transition hover:border-blue-400 hover:bg-blue-50">
                                {{ $label }}
                            </button>
                        @endforeach
                    </span>

                    <span class="mt-4 block text-sm font-semibold text-black">Custom instructions</span>
                    <textarea
                        id="polish-instructions"
                        data-polish-instructions
                        maxlength="2000"
                        rows="7"
                        class="mt-2 w-full resize-y rounded-lg border border-blue-200 bg-white px-4 py-3 text-sm leading-6 text-black outline-none transition placeholder:text-blue-300 focus:border-blue-500 focus:ring-2 focus:ring-blue-100"
                        placeholder="Example: Translate Cebuano, Bisaya, Filipino, and code-switched speech into polished English while preserving names, offices, acronyms, titles, numbers, and meaning."
                    ></textarea>
                </label>
                <p data-polish-instructions-error class="mt-2 hidden text-sm font-semibold text-blue-700">Enter instructions before polishing.</p>
                <p data-polish-replace-warning class="mt-3 border-l-2 border-blue-500 bg-blue-50 px-3 py-2 text-sm leading-6 text-blue-950">
                    Polishing again replaces the current polished transcript.
                </p>
            </div>

            <footer class="flex shrink-0 items-center justify-end gap-2 border-t border-blue-100 px-5 py-4">
                <button type="submit" value="cancel" class="min-h-10 cursor-pointer rounded-lg border border-blue-200 bg-white px-4 py-2 text-sm font-semibold text-blue-900 transition hover:bg-blue-50">
                    Cancel
                </button>
                <button type="button" data-polish-confirm class="min-h-10 cursor-pointer rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-700">
                    Polish transcript
                </button>
            </footer>
        </form>
    @else
        <form method="dialog" class="p-5 sm:p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Polish transcript</p>
                </div>
                <button type="submit" value="cancel" class="inline-flex h-9 w-9 cursor-pointer items-center justify-center rounded-lg border border-white/10 bg-white/[0.03] text-slate-300 transition hover:bg-white/8 hover:text-white" aria-label="Close polish instructions">
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="m6 6 12 12" />
                        <path d="m18 6-12 12" />
                    </svg>
                </button>
            </div>

            <label class="mt-5 block" for="polish-instructions">
                <span class="text-sm font-semibold text-slate-200">Select a preset or enter custom instructions on how to polish the transcript:</span>
                <span class="mt-3 grid gap-2 sm:grid-cols-3" aria-label="Instruction presets">
                    @foreach ([
                        'translate-en' => 'Translate (EN)',
                        'translate-fil' => 'Translate (Filipino)',
                        'fix-grammar' => 'Fix Grammar',
                        'translate-en-fix-grammar' => 'Translate (EN) / Fix Grammar',
                    ] as $preset => $label)
                        <button type="button" data-polish-preset="{{ $preset }}" aria-pressed="false" class="min-h-9 cursor-pointer rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-xs font-semibold text-slate-200 transition hover:border-cyan-300/30 hover:bg-cyan-300/10 hover:text-white">
                            {{ $label }}
                        </button>
                    @endforeach
                </span>
                <textarea
                    id="polish-instructions"
                    data-polish-instructions
                    maxlength="2000"
                    rows="6"
                    class="mt-2 w-full resize-y rounded-lg border border-white/10 bg-slate-900 px-4 py-3 text-sm leading-6 text-white outline-none transition placeholder:text-slate-500 focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20"
                    placeholder="Example: Translate Cebuano, Bisaya, Filipino, and code-switched speech into polished English while preserving names, offices, acronyms, titles, numbers, and meaning."
                ></textarea>
                <p class="mt-1 text-xs text-slate-400">Tip: Be specific. Include source languages, target language, and what to preserve.</p>
            </label>
            <p data-polish-instructions-error class="mt-2 hidden text-sm text-rose-300">Enter instructions before polishing.</p>
            <p data-polish-replace-warning class="mt-3 rounded-lg border border-amber-300/20 bg-amber-300/10 px-3 py-2 text-sm leading-6 text-amber-100">
                Polishing again removes the current polished transcript and replaces it with the new result.
            </p>

            <div class="mt-5 flex justify-end gap-3">
                <button type="submit" value="cancel" class="min-h-9 cursor-pointer rounded-lg border border-white/10 bg-white/[0.03] px-4 py-2 text-sm font-semibold text-slate-200 transition hover:bg-white/8 hover:text-white">
                    Cancel
                </button>
                <button type="button" data-polish-confirm class="min-h-9 cursor-pointer rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200">
                    Polish transcript
                </button>
            </div>
        </form>
    @endif
</dialog>
