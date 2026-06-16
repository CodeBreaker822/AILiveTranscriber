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
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body
        data-page="{{ $activePage }}"
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
        class="min-h-screen overflow-x-hidden bg-[linear-gradient(180deg,_#071018_0%,_#0d1620_42%,_#101820_100%)] font-sans text-slate-100 selection:bg-cyan-300/20 selection:text-white"
    >
        <div class="min-h-screen px-4 py-4 sm:px-6 lg:px-8">
            <div class="mx-auto flex min-h-[calc(100vh-2rem)] w-full max-w-7xl flex-col gap-5">
                <x-app-header :active-page="$activePage" />

                <main class="flex-1">
                    {{ $slot }}
                </main>

                <x-app-footer />
            </div>
        </div>
    </body>
</html>
