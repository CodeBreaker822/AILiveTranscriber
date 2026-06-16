<x-app-layout title="Settings | AI Transcriber" active-page="settings">
    <div class="mx-auto max-w-4xl">
        <section class="rounded-lg border border-white/10 bg-slate-950/70 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl lg:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">Settings</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">API configuration</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Save the API keys used for transcription and transcript cleanup.</p>
                </div>

                <span class="rounded-lg border border-white/10 bg-white/[0.03] px-3 py-1 text-xs uppercase tracking-[0.24em] text-slate-400">
                    {{ $pageStatusLabel }}
                </span>
            </div>

            @if (session('status'))
                <div class="mt-6 rounded-lg border border-emerald-300/20 bg-emerald-300/10 px-4 py-3 text-sm text-emerald-100">
                    {{ session('status') }}
                </div>
            @endif

            @if ($errors->any())
                <div class="mt-6 rounded-lg border border-rose-400/20 bg-rose-400/10 px-4 py-3 text-sm text-rose-100">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="post" action="{{ route('settings.update') }}" class="mt-6 space-y-6">
                @csrf

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">ElevenLabs API key</span>
                    <span class="mt-1 block text-sm leading-6 text-slate-400">Required for converting uploaded or recorded audio into raw transcript text.</span>
                    <input
                        type="text"
                        name="elevenlabs_api_key"
                        value="{{ old('elevenlabs_api_key', $elevenLabsApiKey) }}"
                        autocomplete="off"
                        placeholder="{{ $hasElevenLabsApiKey ? 'Paste a new key to replace the saved key' : 'Paste your ElevenLabs API key' }}"
                        class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white placeholder:text-slate-500 outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20"
                    >
                </label>

                <label class="block">
                    <span class="text-xs font-semibold uppercase tracking-[0.24em] text-slate-400">Gemini API key</span>
                    <span class="mt-1 block text-sm leading-6 text-slate-400">Optional. Used only when you furnish or clean the raw transcript.</span>
                    <input
                        type="text"
                        name="gemini_api_key"
                        value="{{ old('gemini_api_key', $geminiApiKey) }}"
                        autocomplete="off"
                        placeholder="{{ $hasGeminiApiKey ? 'Paste a new key to replace the saved key' : 'Paste your Gemini API key' }}"
                        class="mt-2 w-full rounded-lg border border-white/10 bg-slate-950/80 px-4 py-3 text-sm text-white placeholder:text-slate-500 outline-none transition focus:border-cyan-300/40 focus:ring-2 focus:ring-cyan-300/20"
                    >
                </label>

                <div class="grid gap-4 md:grid-cols-2">
                    @foreach ($providers as $provider)
                        <article class="rounded-lg border p-4 {{ $provider['classes'] }}">
                            <div class="flex items-start justify-between gap-3">
                                <div>
                                    <p class="text-xs uppercase tracking-[0.24em] opacity-75">{{ $provider['name'] }}</p>
                                    <div class="mt-2 flex items-center gap-2">
                                        <span class="h-2.5 w-2.5 rounded-full {{ $provider['dot'] }}"></span>
                                        <p class="text-lg font-semibold">{{ $provider['label'] }}</p>
                                    </div>
                                </div>
                                @if ($provider['checked_at'])
                                    <span class="shrink-0 rounded-md border border-white/10 bg-black/10 px-2 py-1 text-[0.65rem] uppercase tracking-[0.18em] opacity-75">
                                        Tested
                                    </span>
                                @endif
                            </div>

                            @if ($provider['message'])
                                <p class="mt-3 text-sm leading-6 opacity-90">{{ $provider['message'] }}</p>
                            @endif

                            @if ($provider['details'])
                                <dl class="mt-4 grid gap-3 text-sm sm:grid-cols-2">
                                    @foreach ($provider['details'] as $detail)
                                        <div>
                                            <dt class="text-xs uppercase tracking-[0.18em] opacity-60">{{ $detail['label'] }}</dt>
                                            <dd class="mt-1 break-words font-semibold">
                                                {{ $detail['value'] }}
                                            </dd>
                                        </div>
                                    @endforeach
                                </dl>
                            @endif
                        </article>
                    @endforeach
                </div>

                <div class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                    <p class="text-xs uppercase tracking-[0.24em] text-slate-400">Gemini model</p>
                    <p class="mt-2 break-words text-sm font-semibold text-white">{{ $geminiModel }}</p>
                </div>

                <div class="flex justify-end">
                    <button type="submit" class="cursor-pointer inline-flex min-h-11 items-center gap-2 rounded-lg bg-cyan-300 px-4 py-2 text-sm font-semibold text-slate-950 transition hover:bg-cyan-200">
                        <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z" />
                            <path d="M17 21v-8H7v8" />
                            <path d="M7 3v5h8" />
                        </svg>
                        Save and test
                    </button>
                </div>
            </form>
        </section>
    </div>
</x-app-layout>
