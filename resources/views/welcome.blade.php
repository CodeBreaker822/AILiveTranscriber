<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#081018">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>AI Transcriber</title>

        @fonts
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="{{ asset('notification.js') }}"></script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body
        data-upload-url="{{ route('audio-chunks.store') }}"
        data-stored-url="{{ route('audio-chunks.index') }}"
        data-default-user-id="1"
        data-default-category-name=""
        data-play-url-base="{{ url('/audio-chunks') }}"
        data-delete-url-base="{{ url('/audio-chunks') }}"
        class="min-h-screen overflow-x-hidden bg-[radial-gradient(circle_at_top_left,_rgba(93,228,207,0.16),_transparent_28%),radial-gradient(circle_at_bottom_right,_rgba(246,184,76,0.1),_transparent_25%),linear-gradient(180deg,_#070d15_0%,_#09111a_42%,_#0b1520_100%)] font-sans text-slate-100 selection:bg-cyan-300/20 selection:text-white"
    >
        <div class="relative min-h-screen px-4 py-4 sm:px-6 lg:px-8">
            <div class="pointer-events-none absolute inset-0 overflow-hidden">
                <div class="absolute -left-24 -top-24 h-80 w-80 rounded-full bg-cyan-400/10 blur-3xl"></div>
                <div class="absolute -bottom-20 right-[-2rem] h-72 w-72 rounded-full bg-amber-300/10 blur-3xl"></div>
            </div>

            <div class="relative z-10 flex min-h-[calc(100vh-2rem)] w-full flex-col gap-5">
                <header class="rounded-[1.75rem] border border-white/10 bg-slate-950/75 px-5 py-4 shadow-[0_24px_80px_rgba(0,0,0,0.35)] backdrop-blur-xl lg:px-7">
                    <div class="flex items-center gap-4">
                        <div class="flex h-12 w-12 items-center justify-center rounded-2xl border border-cyan-300/20 bg-white/5">
                            <div class="flex h-7 w-7 items-center justify-center rounded-full bg-cyan-300/10 text-cyan-200">
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2">
                                    <path d="M12 18a4 4 0 0 0 4-4V8a4 4 0 1 0-8 0v6a4 4 0 0 0 4 4Z" />
                                    <path d="M5 11v1a7 7 0 0 0 14 0v-1" />
                                    <path d="M12 18v4" />
                                    <path d="M8 22h8" />
                                </svg>
                            </div>
                        </div>

                        <div class="flex flex-wrap items-center gap-3">
                            <h1 class="text-2xl font-semibold tracking-tight text-white sm:text-3xl">AI Transcriber</h1>
                            <span class="inline-flex items-center gap-2 rounded-full border border-rose-400/20 bg-rose-400/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.24em] text-rose-100">
                                <span class="h-2.5 w-2.5 animate-pulse rounded-full bg-rose-400"></span>
                                live
                            </span>
                        </div>
                    </div>
                </header>

                <main class="grid flex-1 gap-5 lg:grid-cols-[1.02fr_0.98fr]">
                    <section class="flex min-h-0 flex-col gap-5">
                        <div class="rounded-[2rem] border border-white/10 bg-slate-950/70 p-5 shadow-[0_24px_80px_rgba(0,0,0,0.35)] backdrop-blur-xl lg:p-7">
                        <div class="flex h-full flex-col gap-6">
                            <div class="flex flex-1 flex-col items-center justify-center gap-16 rounded-[1.75rem] border border-dashed border-white/10 bg-white/[0.02] px-4 py-10 pt-14 md:pt-16">
                                <div class="relative w-full max-w-[28rem]">
                                    <p class="mb-2 text-xs uppercase tracking-[0.3em] text-slate-400">Category</p>
                                    <input
                                        type="text"
                                        data-category-input
                                        placeholder="Choose or type a category"
                                        autocomplete="off"
                                        class="w-full rounded-2xl border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white placeholder:text-slate-500 outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20"
                                    >
                                    <div
                                        data-category-suggestions
                                        class="absolute left-0 right-0 top-full z-20 mt-2 hidden max-h-48 overflow-y-auto rounded-2xl border border-white/10 bg-slate-950/95 p-2 shadow-[0_20px_60px_rgba(0,0,0,0.35)] backdrop-blur-xl"
                                    ></div>
                                </div>

                                <button
                                    type="button"
                                    data-record-toggle
                                    data-recording="false"
                                    aria-pressed="false"
                                    class="group relative cursor-pointer select-none rounded-full outline-none transition-transform duration-200 hover:scale-[1.01] focus-visible:ring-2 focus-visible:ring-cyan-300/60 focus-visible:ring-offset-0"
                                >
                                    <span class="absolute -inset-6 rounded-full border border-cyan-300/15 transition group-data-[recording=true]:border-rose-300/20"></span>
                                    <span class="absolute -inset-10 rounded-full border border-amber-300/10 transition group-data-[recording=true]:border-rose-300/10"></span>

                                    <span class="relative flex aspect-square w-[clamp(16rem,32vw,24rem)] flex-col items-center justify-center rounded-full bg-[radial-gradient(circle_at_32%_32%,rgba(255,255,255,0.2),transparent_26%),radial-gradient(circle_at_50%_50%,rgba(93,228,207,0.18),rgba(8,14,24,0.88)_55%),linear-gradient(180deg,rgba(17,34,50,0.9),rgba(8,14,24,1))] shadow-[0_0_0_1px_rgba(93,228,207,0.18),0_0_42px_rgba(93,228,207,0.12),inset_0_0_0_1px_rgba(255,255,255,0.04)] transition group-data-[recording=true]:bg-[radial-gradient(circle_at_32%_32%,rgba(255,255,255,0.16),transparent_26%),radial-gradient(circle_at_50%_50%,rgba(251,113,133,0.22),rgba(12,14,22,0.92)_55%),linear-gradient(180deg,rgba(60,18,28,0.95),rgba(18,10,14,1))] group-data-[recording=true]:shadow-[0_0_0_1px_rgba(251,113,133,0.18),0_0_42px_rgba(251,113,133,0.16),inset_0_0_0_1px_rgba(255,255,255,0.04)]">
                                        <span class="absolute -inset-5 rounded-full border border-cyan-300/15 transition group-data-[recording=true]:border-rose-300/15"></span>
                                        <span class="absolute -inset-9 rounded-full border border-amber-300/10 transition group-data-[recording=true]:border-rose-300/10"></span>

                                        <span class="relative z-10 grid h-24 w-24 place-items-center rounded-full border border-white/10 bg-white/8 shadow-[0_0_0_1px_rgba(93,228,207,0.14)] transition group-data-[recording=true]:border-rose-300/20 group-data-[recording=true]:shadow-[0_0_0_1px_rgba(251,113,133,0.18)]">
                                            <span data-record-icon="play" class="text-emerald-300">
                                                <svg viewBox="0 0 24 24" class="h-10 w-10 fill-current" aria-hidden="true">
                                                    <path d="M8 5.14v13.72c0 .84.92 1.35 1.63.91l10.72-6.86a1.1 1.1 0 0 0 0-1.86L9.63 4.23A1.08 1.08 0 0 0 8 5.14Z" />
                                                </svg>
                                            </span>
                                            <span data-record-icon="stop" class="hidden text-rose-400">
                                                <svg viewBox="0 0 24 24" class="h-10 w-10 fill-current" aria-hidden="true">
                                                    <rect x="6.5" y="6.5" width="11" height="11" rx="2" />
                                                </svg>
                                            </span>
                                        </span>

                                        <span data-record-state class="relative z-10 mt-6 text-sm uppercase tracking-[0.32em] text-cyan-300">
                                            Listening
                                        </span>
                                        <span data-record-caption class="relative z-10 mt-3 text-4xl font-semibold tracking-tight text-white">
                                            Ready to capture
                                        </span>
                                    </span>
                                </button>
                            </div>
                        </div>
                        </div>

                    </section>

                    <section class="flex min-h-0 flex-col gap-5">
                        <div class="flex h-full min-h-0 flex-1 flex-col rounded-[2rem] border border-white/10 bg-slate-950/70 p-5 shadow-[0_24px_80px_rgba(0,0,0,0.35)] backdrop-blur-xl lg:p-6">
                            <div class="flex flex-wrap items-center justify-between gap-3">
                                <div>
                                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Live queue</p>
                                    <h2 class="mt-2 text-2xl font-semibold tracking-tight text-white">Pending clips</h2>
                                </div>
                                <div class="rounded-full border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.26em] text-slate-400">
                                    <span data-audio-count>0</span> parked
                                </div>
                            </div>

                            <div class="mt-5 rounded-[1.5rem] border border-white/10 bg-white/[0.03] p-4">
                                <div class="flex flex-wrap items-center justify-between gap-4">
                                    <div>
                                        <p class="text-xs uppercase tracking-[0.3em] text-slate-400">Now</p>
                                        <p data-audio-active-name class="mt-2 text-lg font-semibold text-white">Ready</p>
                                        <p data-audio-active-meta class="mt-1 text-sm text-slate-400">No audio yet</p>
                                        <p data-audio-active-note class="mt-1 text-xs uppercase tracking-[0.24em] text-cyan-300"></p>
                                    </div>
                                    <div class="text-right">
                                        <p data-audio-progress-label class="mt-2 text-lg font-semibold text-white">00:00:00</p>
                                    </div>
                                </div>

                                <div class="mt-4 h-3 overflow-hidden rounded-full bg-slate-800/80">
                                    <div data-audio-progress class="h-full w-0 rounded-full bg-gradient-to-r from-cyan-400 via-emerald-300 to-amber-300 transition-[width] duration-150"></div>
                                </div>
                                <p data-audio-support class="mt-3 text-xs uppercase tracking-[0.24em] text-slate-500">Ready</p>
                            </div>

                            <div data-audio-queue class="mt-5 min-h-0 flex-1 space-y-3 overflow-y-auto pr-2">
                                <div data-audio-empty class="rounded-[1.5rem] border border-dashed border-cyan-300/20 bg-cyan-300/5 p-4">
                                    <p class="text-sm text-slate-200">No pending recordings yet.</p>
                                </div>
                            </div>
                        </div>
                    </section>
                </main>

                <section class="rounded-[2rem] border border-white/10 bg-slate-950/70 p-5 shadow-[0_24px_80px_rgba(0,0,0,0.35)] backdrop-blur-xl lg:p-6">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="flex items-center gap-3">
                            <h2 class="text-2xl font-semibold tracking-tight text-white">Transciption</h2>
                            <span data-current-category class="rounded-full border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.26em] text-slate-400">
                                Choose category
                            </span>
                        </div>
                        <div class="rounded-full border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.26em] text-slate-400">
                            <span data-stored-count>0</span> stored
                        </div>
                    </div>

                    <div data-stored-list class="mt-5 w-full max-h-[38vh] overflow-y-auto pr-2">
                        <div data-stored-empty class="w-full py-4">
                            <p class="text-sm text-slate-200">No entries yet.</p>
                        </div>
                    </div>
                </section>
            </div>
        </div>
    </body>
</html>
