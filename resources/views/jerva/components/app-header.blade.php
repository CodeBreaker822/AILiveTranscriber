@props([
    'activePage' => 'workspace',
    'hasOfflineTranscriptionModel' => false,
])

<header data-app-header class="shrink-0 border-b border-blue-100 bg-white px-6 py-4">
    <div class="flex items-center justify-between gap-4">
        <a href="{{ route('transcription.workspace') }}" class="flex min-w-0 items-center gap-3">
            <img
                src="{{ asset(config('app.brand_logo', 'AILogo.png')) }}"
                alt="{{ config('app.brand_name') }}"
                class="h-10 w-10 shrink-0 rounded-lg object-contain"
            >
            <span class="truncate text-base font-semibold text-black">{{ config('app.brand_name') }}</span>
        </a>

        <a
            href="{{ route('transcription.workspace') }}"
            aria-current="{{ $activePage === 'workspace' ? 'page' : 'false' }}"
            class="h-10 rounded-lg border border-blue-200 bg-blue-50 px-4 text-sm font-semibold leading-10 text-blue-700 transition hover:border-blue-400 hover:bg-blue-100"
        >
            Workspace
        </a>
    </div>
</header>
