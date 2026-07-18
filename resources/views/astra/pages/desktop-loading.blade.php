@php
    $jervaEdition = config('app.edition') === 'jerva';
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="{{ $jervaEdition ? '#ffffff' : '#081018' }}">
        <title>Starting {{ config('app.brand_name') }}</title>
        <style>
            :root {
                color-scheme: {{ $jervaEdition ? 'light' : 'dark' }};
                font-family: "Instrument Sans", "Segoe UI", sans-serif;
                background: {{ $jervaEdition ? '#ffffff' : '#071018' }};
                color: {{ $jervaEdition ? '#000000' : '#e2e8f0' }};
            }

            * {
                box-sizing: border-box;
            }

            body {
                align-items: center;
                background: {{ $jervaEdition ? '#ffffff' : 'linear-gradient(180deg, #071018 0%, #0d1620 52%, #101820 100%)' }};
                display: flex;
                height: 100vh;
                justify-content: center;
                margin: 0;
                overflow: hidden;
            }

            main {
                max-width: 28rem;
                padding: 2rem;
                text-align: center;
                width: min(100%, 32rem);
            }

            img {
                height: 4rem;
                margin-bottom: 1.5rem;
                width: 4rem;
            }

            h1 {
                font-size: 1.5rem;
                line-height: 1.2;
                margin: 0;
            }

            p {
                color: {{ $jervaEdition ? '#1e3a8a' : '#94a3b8' }};
                font-size: 0.9rem;
                line-height: 1.6;
                margin: 0.75rem 0 0;
            }

            .track {
                background: {{ $jervaEdition ? '#dbeafe' : 'rgba(148, 163, 184, 0.18)' }};
                border-radius: 999px;
                border: {{ $jervaEdition ? '1px solid #bfdbfe' : '0' }};
                height: 0.5rem;
                margin-top: 1.5rem;
                overflow: hidden;
            }

            .bar {
                animation: load 1.25s ease-in-out infinite;
                background: {{ $jervaEdition ? '#2563eb' : 'linear-gradient(90deg, #22d3ee, #34d399, #fbbf24)' }};
                border-radius: inherit;
                height: 100%;
                width: 45%;
            }

            .status {
                color: {{ $jervaEdition ? '#2563eb' : '#67e8f9' }};
                font-size: 0.72rem;
                font-weight: 700;
                letter-spacing: 0.18em;
                margin-top: 1rem;
                text-transform: uppercase;
            }

            @keyframes load {
                0% {
                    transform: translateX(-110%);
                }

                100% {
                    transform: translateX(230%);
                }
            }
        </style>
    </head>
    <body>
        <main>
            <img src="{{ asset(config('app.brand_logo', 'AILogo.png')) }}" alt="">
            <h1>Starting {{ config('app.brand_name') }}</h1>
            <p>The desktop app is preparing its local workspace and frontend assets.</p>
            <div class="track" aria-hidden="true">
                <div class="bar"></div>
            </div>
            <div class="status" data-status>Preparing interface</div>
        </main>

        <script>
            const status = document.querySelector('[data-status]');
            const messages = [
                'Preparing interface',
                'Checking local server',
                'Loading app assets',
                'Almost ready',
            ];
            let attempts = 0;
            const MIN_LOAD_TIME_MS = 1800;
            const startTime = Date.now();

            const poll = async () => {
                attempts += 1;
                status.textContent = messages[Math.min(messages.length - 1, Math.floor(attempts / 4))];

                try {
                    const response = await fetch('{{ route('desktop.assets-ready') }}', {
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                    });

                    if (response.ok) {
                        const elapsed = Date.now() - startTime;
                        const remaining = Math.max(0, MIN_LOAD_TIME_MS - elapsed);

                        window.setTimeout(() => {
                            window.location.replace('{{ route('transcription.live') }}');
                        }, remaining);
                        return;
                    }
                } catch (error) {
                }

                window.setTimeout(poll, 750);
            };

            poll();
        </script>
    </body>
</html>
