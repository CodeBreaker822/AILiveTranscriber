<x-app-layout title="License Help | AI Transcriber" active-page="settings">
    <div class="mx-auto max-w-3xl">
        <section class="rounded-lg border border-white/10 bg-slate-950/70 p-5 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl lg:p-7">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-[0.3em] text-cyan-300">License help</p>
                    <h1 class="mt-2 text-3xl font-semibold tracking-tight text-white">Use your AITranscriber license key</h1>
                    <p class="mt-2 max-w-2xl text-sm leading-6 text-slate-400">Paste the license key issued by the API Manager into Settings, then save and test it. Available providers, models, and languages will load automatically from the server.</p>
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

            <div class="mt-7 grid gap-4 md:grid-cols-3">
                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">1</p>
                    <h2 class="mt-2 text-lg font-semibold text-white">Get the license</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">Use the license key generated for this workstation or office deployment.</p>
                </article>

                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">2</p>
                    <h2 class="mt-2 text-lg font-semibold text-white">Save and test</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">Settings checks the server and stores the returned provider and model capabilities.</p>
                </article>

                <article class="rounded-lg border border-white/10 bg-white/[0.03] p-4">
                    <p class="text-xs font-semibold uppercase tracking-[0.24em] text-cyan-300">3</p>
                    <h2 class="mt-2 text-lg font-semibold text-white">Start transcribing</h2>
                    <p class="mt-2 text-sm leading-6 text-slate-400">Live and uploaded audio use the server-approved language list for the selected provider model.</p>
                </article>
            </div>
        </section>
    </div>
</x-app-layout>
