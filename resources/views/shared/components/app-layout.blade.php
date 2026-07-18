@props([
    'title' => null,
    'activePage' => 'live',
    'focusedWorkspace' => false,
    'headerView' => null,
    'footerView' => null,
    'modalNamespace' => 'shared.modals',
])

@php
    $settings = app(\App\Services\Config\AppSettingsService::class);
    $offlineModels = app(\App\Services\Speech\OfflineWhisperModelService::class);
    $jervaEdition = config('app.edition') === 'jerva';
    $resourceProfile = $resourceProfile ?? $settings->resourceProfile();
    $hasOfflineTranscriptionModel = $hasOfflineTranscriptionModel ?? $offlineModels->hasSupportedInstalledModel();
    $speechProvider = $speechProvider ?? $settings->speechToTextProvider();
    $audioChunkSeconds = $audioChunkSeconds ?? $settings->audioChunkSeconds();
    $transcribeMaxBatchDurationMs = $transcribeMaxBatchDurationMs ?? $settings->transcribeMaxBatchDurationMs() ?? 1_200_000;
    $transcribeMaxBatchClips = $transcribeMaxBatchClips ?? $settings->transcribeMaxBatchClips() ?? 20;
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-ui-page="{{ $activePage }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="{{ $jervaEdition ? '#ffffff' : '#081018' }}">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $title ?? config('app.brand_name') }}</title>

        @fonts
        <script src="{{ asset('jquery-4.0.0.min.js') }}"></script>
        <script src="{{ asset('notification.js') }}"></script>
        <script src="{{ asset('loader.js') }}"></script>
        <script src="{{ asset('js/modals/sidebar.js') }}"></script>
        <script src="{{ asset('js/modals/polish-instructions.js') }}"></script>
        <script src="{{ asset('js/shared/background-jobs.js') }}"></script>
        <script src="{{ asset('js/modals/transcript-summary.js') }}" defer></script>
        <style>
            [data-desktop-startup-overlay] {
                align-items: center;
                background: {{ $jervaEdition ? '#ffffff' : 'linear-gradient(180deg, #071018 0%, #0d1620 52%, #101820 100%)' }};
                color: {{ $jervaEdition ? '#000000' : '#e2e8f0' }};
                display: flex;
                font-family: "Instrument Sans", "Segoe UI", sans-serif;
                inset: 0;
                justify-content: center;
                position: fixed;
                z-index: 99999;
            }

            [data-desktop-startup-overlay] main {
                max-width: 28rem;
                padding: 2rem;
                text-align: center;
                width: min(100%, 32rem);
            }

            [data-desktop-startup-overlay] img {
                height: 4rem;
                margin-bottom: 1.5rem;
                width: 4rem;
            }

            [data-desktop-startup-overlay] h1 {
                font-size: 1.5rem;
                line-height: 1.2;
                margin: 0;
            }

            [data-desktop-startup-overlay] p {
                color: {{ $jervaEdition ? '#1e3a8a' : '#94a3b8' }};
                font-size: 0.9rem;
                line-height: 1.6;
                margin: 0.75rem 0 0;
            }

            [data-desktop-startup-overlay] .track {
                background: {{ $jervaEdition ? '#dbeafe' : 'rgba(148, 163, 184, 0.18)' }};
                border-radius: 999px;
                border: {{ $jervaEdition ? '1px solid #bfdbfe' : '0' }};
                height: 0.5rem;
                margin-top: 1.5rem;
                overflow: hidden;
            }

            [data-desktop-startup-overlay] .bar {
                animation: desktop-startup-load 1.25s ease-in-out infinite;
                background: {{ $jervaEdition ? '#2563eb' : 'linear-gradient(90deg, #22d3ee, #34d399, #fbbf24)' }};
                border-radius: inherit;
                height: 100%;
                width: 45%;
            }

            [data-desktop-startup-overlay] .status {
                color: {{ $jervaEdition ? '#2563eb' : '#67e8f9' }};
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.18em;
                margin-top: 1rem;
                text-transform: uppercase;
            }

            @keyframes desktop-startup-load {
                0% {
                    transform: translateX(-110%);
                }

                100% {
                    transform: translateX(230%);
                }
            }
        </style>
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body
        data-page="{{ $activePage }}"
        data-app-brand-name="{{ config('app.brand_name') }}"
        data-focused-workspace="{{ $focusedWorkspace ? 'true' : 'false' }}"
        data-desktop-dev="{{ config('app.desktop_dev') ? 'true' : 'false' }}"
        data-speech-provider="{{ $speechProvider }}"
        data-update-connectivity-url="{{ route('app-update.connectivity') }}"
        data-offline-model-status-url="{{ route('offline-model.status') }}"
        data-offline-model-download-url="{{ route('offline-model.download') }}"
        data-speaker-session-release-url="{{ route('speaker-sessions.release') }}"
        data-summary-status-url="{{ route('transcripts.summary.show') }}"
        data-summary-store-url="{{ route('transcripts.summary.store') }}"
        data-resource-cpu-threads="{{ $resourceProfile['cpu_threads'] }}"
        data-resource-memory-budget-mb="{{ $resourceProfile['memory_budget_mb'] }}"
        data-resource-gpu-available="{{ $resourceProfile['gpu_available'] ? 'true' : 'false' }}"
        data-resource-gpu-vram-budget-mb="{{ $resourceProfile['gpu_vram_budget_mb'] }}"
        data-audio-chunk-seconds="{{ $audioChunkSeconds }}"
        @if ($activePage === 'live')
            data-upload-url="{{ route('audio-chunks.store') }}"
            data-stored-url="{{ route('audio-chunks.index') }}"
            data-vad-log-url="{{ route('audio-vad-logs.index') }}"
            data-furnish-url="{{ route('transcripts.furnish') }}"
            data-export-url="{{ route('transcripts.export') }}"
            data-default-user-id="1"
            data-default-category-name=""
            data-play-url-base="{{ url('/audio-chunks') }}"
            data-delete-url-base="{{ url('/audio-chunks') }}"
            @if ($focusedWorkspace)
                data-upload-audio-url="{{ route('audio-uploads.store') }}"
                data-upload-audio-prepare-url="{{ route('audio-uploads.sections.prepare') }}"
                data-upload-audio-prepare-batch-url="{{ route('audio-uploads.sections.prepare-batch') }}"
                data-upload-audio-diarize-url="{{ route('audio-uploads.sections.diarize') }}"
                data-upload-session-status-url="{{ route('audio-uploads.sessions.status') }}"
                data-audio-chunk-url="{{ route('audio-chunks.store') }}"
                data-audio-chunk-batch-url="{{ route('audio-chunks.store-batch') }}"
                data-audio-chunk-status-url="{{ route('audio-chunks.status') }}"
                data-transcribe-max-batch-duration-ms="{{ $transcribeMaxBatchDurationMs }}"
                data-transcribe-max-batch-clips="{{ $transcribeMaxBatchClips }}"
            @endif
        @elseif ($activePage === 'workspace')
            data-upload-url="{{ route('audio-chunks.store') }}"
            data-upload-audio-url="{{ route('audio-uploads.store') }}"
            data-upload-audio-prepare-url="{{ route('audio-uploads.sections.prepare') }}"
            data-upload-audio-prepare-batch-url="{{ route('audio-uploads.sections.prepare-batch') }}"
            data-upload-audio-diarize-url="{{ route('audio-uploads.sections.diarize') }}"
            data-upload-session-status-url="{{ route('audio-uploads.sessions.status') }}"
            data-audio-chunk-url="{{ route('audio-chunks.store') }}"
            data-audio-chunk-batch-url="{{ route('audio-chunks.store-batch') }}"
            data-audio-chunk-status-url="{{ route('audio-chunks.status') }}"
            data-transcribe-max-batch-duration-ms="{{ $transcribeMaxBatchDurationMs }}"
            data-transcribe-max-batch-clips="{{ $transcribeMaxBatchClips }}"
            data-stored-url="{{ route('audio-chunks.index') }}"
            data-vad-log-url="{{ route('audio-vad-logs.index') }}"
            data-furnish-url="{{ route('transcripts.furnish') }}"
            data-export-url="{{ route('transcripts.export') }}"
            data-default-user-id="1"
            data-default-category-name=""
            data-play-url-base="{{ url('/audio-chunks') }}"
            data-delete-url-base="{{ url('/audio-chunks') }}"
        @elseif ($activePage === 'upload')
            data-upload-audio-url="{{ route('audio-uploads.store') }}"
            data-upload-audio-prepare-url="{{ route('audio-uploads.sections.prepare') }}"
            data-upload-audio-prepare-batch-url="{{ route('audio-uploads.sections.prepare-batch') }}"
            data-upload-audio-diarize-url="{{ route('audio-uploads.sections.diarize') }}"
            data-upload-session-status-url="{{ route('audio-uploads.sessions.status') }}"
            data-audio-chunk-url="{{ route('audio-chunks.store') }}"
            data-audio-chunk-batch-url="{{ route('audio-chunks.store-batch') }}"
            data-audio-chunk-status-url="{{ route('audio-chunks.status') }}"
            data-transcribe-max-batch-duration-ms="{{ $transcribeMaxBatchDurationMs }}"
            data-transcribe-max-batch-clips="{{ $transcribeMaxBatchClips }}"
            data-stored-url="{{ route('audio-chunks.index') }}"
            data-vad-log-url="{{ route('audio-vad-logs.index') }}"
            data-furnish-url="{{ route('transcripts.furnish') }}"
            data-export-url="{{ route('transcripts.export') }}"
            data-default-user-id="1"
        @endif
        class="h-[100dvh] overflow-hidden bg-[linear-gradient(180deg,_#071018_0%,_#0d1620_42%,_#101820_100%)] font-sans text-slate-100 selection:bg-cyan-300/20 selection:text-white"
    >
        @if (config('app.desktop_dev'))
            <div data-desktop-startup-overlay>
                <main>
                    <img src="{{ asset(config('app.brand_logo', 'AILogo.png')) }}" alt="">
                    <h1>Starting {{ config('app.brand_name') }}</h1>
                    <p>The workspace is loading its interface.</p>
                    <div class="track" aria-hidden="true">
                        <div class="bar"></div>
                    </div>
                    <div class="status" data-desktop-startup-status>Loading workspace</div>
                </main>
            </div>
        @endif

        <div data-app-shell class="{{ $focusedWorkspace ? 'h-full p-0' : 'h-full p-3 sm:p-4' }}">
            <div data-app-frame class="{{ $focusedWorkspace ? 'flex h-full min-h-0 w-full flex-col gap-0' : 'mx-auto flex h-full min-h-0 w-full max-w-7xl flex-col gap-3' }}">
                @unless ($focusedWorkspace)
                    @if ($headerView)
                        @include($headerView, [
                            'activePage' => $activePage,
                            'hasOfflineTranscriptionModel' => $hasOfflineTranscriptionModel,
                        ])
                    @endif
                @endunless

                <main data-app-main class="min-h-0 flex-1 {{ in_array($activePage, ['live', 'upload', 'workspace'], true) ? 'overflow-hidden' : 'overflow-y-auto' }}">
                    {{ $slot }}
                </main>

                @unless ($focusedWorkspace)
                    @if ($footerView)
                        @include($footerView)
                    @endif
                @endunless
            </div>
        </div>

        @if ($focusedWorkspace)
            @include($modalNamespace.'.app-update', ['activePage' => $activePage])
            @include($modalNamespace.'.offline-model', ['activePage' => $activePage])
            <script src="{{ asset('js/modals/app-update.js') }}" defer></script>
            <script src="{{ asset('js/offline-model.js') }}" defer></script>
        @endif

        @if (in_array($activePage, ['live', 'upload', 'workspace'], true))
            @include($modalNamespace.'.polish-instructions')
            @include($modalNamespace.'.transcript-summary')
            @include($modalNamespace.'.pending-clips-sidebar', ['activePage' => $activePage])
        @endif
    </body>
</html>
