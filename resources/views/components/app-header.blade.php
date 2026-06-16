@props([
    'activePage' => 'live',
])

@php
    $navItems = [
        [
            'key' => 'live',
            'label' => 'Live',
            'href' => route('transcription.live'),
            'icon' => 'mic',
        ],
        [
            'key' => 'upload',
            'label' => 'Upload',
            'href' => route('transcription.upload'),
            'icon' => 'upload',
        ],
    ];
@endphp

<header class="rounded-lg border border-white/10 bg-slate-950/80 px-5 py-4 shadow-[0_18px_60px_rgba(0,0,0,0.32)] backdrop-blur-xl lg:px-7">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <a href="{{ route('transcription.live') }}" class="flex min-w-0 items-center gap-4">
            <img
                src="{{ asset('AILogo.png') }}"
                alt="AI Transcriber"
                class="h-16 w-16 shrink-0 rounded-lg object-contain"
            >

            <span class="min-w-0">
                <span class="block text-2xl font-semibold tracking-tight text-white sm:text-3xl">AI Transcriber</span>
                <span class="mt-1 block text-sm text-slate-400">Capture live speech or prepare long audio files.</span>
            </span>
        </a>

        <div class="flex w-full items-center gap-2 lg:w-auto">
            <nav aria-label="Transcription tools" class="flex min-w-0 flex-1 rounded-lg border border-white/10 bg-white/[0.03] p-1 lg:w-auto lg:flex-none">
                @foreach ($navItems as $item)
                    @php
                        $isActive = $activePage === $item['key'];
                    @endphp

                    <a
                        href="{{ $item['href'] }}"
                        aria-current="{{ $isActive ? 'page' : 'false' }}"
                        class="inline-flex min-h-11 flex-1 items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium transition lg:flex-none {{ $isActive ? 'bg-cyan-300 text-slate-950 shadow-[0_10px_30px_rgba(103,232,249,0.16)]' : 'text-slate-300 hover:bg-white/8 hover:text-white' }}"
                    >
                        @if ($item['icon'] === 'mic')
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 18a4 4 0 0 0 4-4V8a4 4 0 1 0-8 0v6a4 4 0 0 0 4 4Z" />
                                <path d="M5 11v1a7 7 0 0 0 14 0v-1" />
                                <path d="M12 18v4" />
                                <path d="M8 22h8" />
                            </svg>
                        @else
                            <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M12 3v12" />
                                <path d="m7 8 5-5 5 5" />
                                <path d="M5 21h14" />
                                <path d="M5 17h14" />
                            </svg>
                        @endif
                        <span>{{ $item['label'] }}</span>
                    </a>
                @endforeach
            </nav>

            <a
                href="{{ route('settings.edit') }}"
                aria-label="Settings"
                title="Settings"
                aria-current="{{ $activePage === 'settings' ? 'page' : 'false' }}"
                class="grid h-12 w-12 shrink-0 place-items-center rounded-lg border border-white/10 transition {{ $activePage === 'settings' ? 'bg-cyan-300 text-slate-950 shadow-[0_10px_30px_rgba(103,232,249,0.16)]' : 'bg-white/[0.03] text-slate-300 hover:bg-white/8 hover:text-white' }}"
            >
                <svg viewBox="0 0 24 24" class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="M12 15.5a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Z" />
                    <path d="M19.4 15a1.7 1.7 0 0 0 .34 1.88l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06A1.7 1.7 0 0 0 15 19.4a1.7 1.7 0 0 0-1 .6V20a2 2 0 1 1-4 0v-.09a1.7 1.7 0 0 0-1-.6 1.7 1.7 0 0 0-1.88.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 4.6 15a1.7 1.7 0 0 0-.6-1H4a2 2 0 1 1 0-4h.09a1.7 1.7 0 0 0 .6-1 1.7 1.7 0 0 0-.34-1.88l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 9 4.6a1.7 1.7 0 0 0 1-.6V4a2 2 0 1 1 4 0v.09a1.7 1.7 0 0 0 1 .6 1.7 1.7 0 0 0 1.88-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9c.22.31.42.64.6 1H20a2 2 0 1 1 0 4h-.09c-.18.36-.38.69-.6 1Z" />
                </svg>
            </a>
        </div>
    </div>
</header>
