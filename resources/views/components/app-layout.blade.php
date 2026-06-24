@props([
    'title' => 'AI Transcriber',
    'activePage' => 'live',
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#081018">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title }}</title>

        @fonts
        <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
        <script src="{{ asset('notification.js') }}"></script>
        <script src="{{ asset('loader.js') }}"></script>
        <script src="{{ asset('js/modals/sidebar.js') }}"></script>
        <script src="{{ asset('js/modals/polish-instructions.js') }}"></script>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body
        data-page="{{ $activePage }}"
        data-speech-provider="{{ app(\App\Services\AppSettingsService::class)->speechToTextProvider() }}"
        @if ($activePage === 'live')
            data-upload-url="{{ route('audio-chunks.store') }}"
            data-stored-url="{{ route('audio-chunks.index') }}"
            data-furnish-url="{{ route('transcripts.furnish') }}"
            data-default-user-id="1"
            data-default-category-name=""
            data-play-url-base="{{ url('/audio-chunks') }}"
            data-delete-url-base="{{ url('/audio-chunks') }}"
        @elseif ($activePage === 'upload')
            data-upload-audio-url="{{ route('audio-uploads.store') }}"
            data-audio-chunk-url="{{ route('audio-chunks.store') }}"
            data-stored-url="{{ route('audio-chunks.index') }}"
            data-furnish-url="{{ route('transcripts.furnish') }}"
            data-default-user-id="1"
        @endif
        class="h-[100dvh] overflow-hidden bg-[linear-gradient(180deg,_#071018_0%,_#0d1620_42%,_#101820_100%)] font-sans text-slate-100 selection:bg-cyan-300/20 selection:text-white"
    >
        <div class="h-full p-3 sm:p-4">
            <div class="mx-auto flex h-full min-h-0 w-full max-w-7xl flex-col gap-3">
                <x-app-header :active-page="$activePage" />

                <main class="min-h-0 flex-1 {{ in_array($activePage, ['live', 'upload'], true) ? 'overflow-hidden' : 'overflow-y-auto' }}">
                    {{ $slot }}
                </main>

                <x-app-footer />
            </div>
        </div>

        @if (in_array($activePage, ['live', 'upload'], true))
            @include('modals.polish-instructions')
            @include('modals.pending-clips-sidebar', ['activePage' => $activePage])
        @endif
    </body>
</html>
