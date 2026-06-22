<x-app-layout title="API Key Help | AI Transcriber" active-page="settings">
    <div class="mx-auto max-w-5xl">
        <section class="rounded-lg border border-white/10 bg-slate-950/70 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl lg:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">API help</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">Get your API keys</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Open a provider key page, create a key, then paste it into AITranscriber Settings.</p>
                </div>

                <a
                    href="{{ route('settings.edit') }}"
                    class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg border border-white/10 bg-white/[0.03] px-3 py-2 text-sm font-semibold text-slate-200 transition hover:bg-white/8 hover:text-white"
                >
                    <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path d="m12 19-7-7 7-7" />
                        <path d="M19 12H5" />
                    </svg>
                    Back to settings
                </a>
            </div>

            <div class="mt-7 grid gap-5">
                @foreach ($providers as $provider)
                    <article id="{{ $provider['id'] }}" class="scroll-mt-6 rounded-lg border border-white/10 bg-white/[0.03] p-5">
                        <div class="flex flex-wrap items-start justify-between gap-4">
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">{{ $provider['name'] }}</p>
                                <h2 class="mt-2 text-2xl font-semibold text-white">{{ $provider['name'] }} API key</h2>
                            </div>

                            <a
                                href="{{ $provider['key_url'] }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex min-h-10 cursor-pointer items-center gap-2 rounded-lg bg-cyan-300 px-3 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200"
                            >
                                Open {{ $provider['name'] }} key page
                                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                    <path d="M7 17 17 7" />
                                    <path d="M7 7h10v10" />
                                </svg>
                            </a>
                        </div>

                        <ol class="mt-5 grid gap-3 md:grid-cols-2">
                            @foreach ($provider['steps'] as $step)
                                <li class="flex gap-3 rounded-lg border border-white/10 bg-slate-950/60 p-4">
                                    <span class="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-cyan-300 text-sm font-semibold text-slate-950">{{ $loop->iteration }}</span>
                                    <p class="text-sm leading-6 text-slate-200">{{ $step }}</p>
                                </li>
                            @endforeach
                        </ol>
                    </article>
                @endforeach
            </div>
        </section>
    </div>
</x-app-layout>
