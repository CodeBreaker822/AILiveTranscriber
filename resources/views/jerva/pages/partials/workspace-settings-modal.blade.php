@props([
    'apiBaseUrl' => '',
    'hasLicenseKey' => false,
    'licenseKeySuffix' => null,
    'licenseStatusLabel' => 'Not configured',
    'licenseStatusMessage' => '',
    'licenseRefreshError' => null,
    'transcriptionProviders' => [],
    'providerPayload' => [],
    'selectedProvider' => '',
    'selectedModel' => '',
    'resourceProfile' => [],
    'audioMemory' => [],
    'transcriptMemory' => [],
])

@php
    $resourceProfile = array_replace([
        'mode' => 'auto',
        'gpu_available' => false,
        'gpu_name' => '',
        'auto_cpu_threads' => 1,
        'cpu_threads' => 1,
        'max_cpu_threads' => 1,
        'auto_memory_budget_mb' => 0,
        'memory_budget_mb' => 0,
        'max_memory_budget_mb' => 0,
        'auto_gpu_vram_budget_mb' => 0,
        'gpu_vram_budget_mb' => 0,
        'max_gpu_vram_budget_mb' => 0,
    ], $resourceProfile);
    $audioMemory = array_replace_recursive([
        'total' => ['formatted_size' => '0 B'],
        'temporary' => ['formatted_size' => '0 B', 'sessions' => 0, 'files' => 0],
        'stored' => ['formatted_size' => '0 B', 'records' => 0],
    ], $audioMemory);
    $transcriptMemory = array_replace_recursive([
        'total' => ['formatted_size' => '0 B'],
        'raw' => ['formatted_size' => '0 B', 'records' => 0],
        'cleaned' => ['formatted_size' => '0 B', 'records' => 0],
    ], $transcriptMemory);
    $settingNavItems = [
        ['id' => 'server', 'label' => 'Server'],
        ['id' => 'transcription', 'label' => 'Transcription'],
        ['id' => 'resources', 'label' => 'Resources'],
        ['id' => 'memory', 'label' => 'Memory'],
    ];
    $settingsInputClass = 'mt-2 h-11 w-full rounded-lg border border-blue-200 bg-white px-3 text-sm text-black outline-none transition focus:border-blue-500 focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-blue-50 disabled:text-blue-300';
@endphp

<div data-chat-settings-modal class="fixed inset-0 z-50 hidden bg-blue-950/30 p-6 opacity-0 transition-opacity duration-200">
    <div class="mx-auto flex h-full max-w-5xl flex-col overflow-hidden rounded-lg bg-white shadow-2xl">
        <header class="flex h-16 shrink-0 items-center justify-between border-b border-blue-200 px-5">
            <div>
                <h2 class="text-lg font-semibold text-black">Settings</h2>
                <p class="text-sm text-blue-900">Workspace preferences and app memory.</p>
            </div>
            <button type="button" data-chat-settings-close aria-label="Close settings" class="grid h-10 w-10 cursor-pointer place-items-center rounded-lg border border-blue-200 text-blue-900 transition hover:border-blue-400 hover:bg-blue-50 hover:text-blue-700">
                <svg viewBox="0 0 24 24" class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path d="m6 6 12 12" />
                    <path d="m18 6-12 12" />
                </svg>
            </button>
        </header>

        <div class="grid min-h-0 flex-1 grid-cols-[14rem_minmax(0,1fr)]">
            <aside class="min-h-0 border-r border-blue-200 bg-white p-3">
                <nav class="space-y-1" aria-label="Settings sections">
                    @foreach ($settingNavItems as $item)
                        <button
                            type="button"
                            data-chat-settings-tab="{{ $item['id'] }}"
                            class="flex h-10 w-full cursor-pointer items-center rounded-lg px-3 text-left text-sm font-medium transition {{ $loop->first ? 'bg-blue-100 text-blue-800 shadow-[inset_3px_0_0_#2563eb]' : 'text-black hover:bg-blue-50 hover:text-blue-700' }}"
                        >
                            {{ $item['label'] }}
                        </button>
                    @endforeach
                </nav>
            </aside>

            <div class="min-h-0 overflow-y-auto px-6 py-5 [scrollbar-gutter:stable]">
                @if (session('status'))
                    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                        {{ session('status') }}
                    </div>
                @endif

                @if ($errors->any())
                    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                        {{ $errors->first() }}
                    </div>
                @endif

                @if ($licenseRefreshError)
                    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
                        {{ $licenseRefreshError }}
                    </div>
                @endif

                <form method="post" action="{{ route('settings.update') }}" data-settings-form data-provider-models='@json($providerPayload, JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT)'>
                    @csrf
                    <input type="hidden" name="return_to" value="workspace">

                    <section data-chat-settings-panel="server" class="space-y-5">
                        <div>
                            <p class="text-xs font-semibold uppercase text-blue-600">Server</p>
                            <h3 class="mt-1 text-xl font-semibold text-black">License and API</h3>
                            <p class="mt-1 text-sm leading-6 text-blue-900">Connect the workspace to the hosted transcription API.</p>
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-semibold text-black">Server base URL</span>
                                <input type="text" name="api_base_url" value="{{ old('api_base_url', $apiBaseUrl) }}" autocomplete="off" placeholder="https://your-transcription-server.example/api" class="{{ $settingsInputClass }}">
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-black">License key</span>
                                <input type="password" name="license_key" value="{{ old('license_key') }}" autocomplete="off" placeholder="{{ $hasLicenseKey ? 'Paste a new license key to replace the saved one' : 'Paste your license key' }}" class="{{ $settingsInputClass }}">
                                @if ($licenseKeySuffix)
                                    <span class="mt-2 block text-xs text-blue-900">Saved key ending in {{ $licenseKeySuffix }}</span>
                                @endif
                            </label>
                        </div>

                        <div class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3">
                            <p data-settings-license-status-label class="text-sm font-semibold text-blue-800">{{ $licenseStatusLabel }}</p>
                            <p data-settings-license-status-message class="mt-1 text-sm leading-6 text-black">{{ $licenseStatusMessage }}</p>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" data-settings-save class="h-11 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-70">Save and test</button>
                        </div>
                    </section>

                    <section data-chat-settings-panel="transcription" class="hidden space-y-5">
                        <div>
                            <p class="text-xs font-semibold uppercase text-blue-600">Transcription</p>
                            <h3 class="mt-1 text-xl font-semibold text-black">Provider and model</h3>
                            <p class="mt-1 text-sm leading-6 text-blue-900">Choose the hosted speech provider and the default model used by online mode.</p>
                        </div>

                        @if ($transcriptionProviders !== [])
                            <div class="grid gap-4 md:grid-cols-2">
                                <label class="block">
                                    <span class="text-sm font-semibold text-black">Speech provider</span>
                                    <select name="speech_to_text_provider" data-server-provider-select class="{{ $settingsInputClass }}">
                                        @foreach ($transcriptionProviders as $provider)
                                            <option value="{{ $provider['provider'] }}" @selected(old('speech_to_text_provider', $selectedProvider) === $provider['provider'])>
                                                {{ $provider['name'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>

                                <label class="block">
                                    <span class="text-sm font-semibold text-black">Model</span>
                                    <select name="speech_to_text_model" data-server-model-select data-selected-model="{{ old('speech_to_text_model', $selectedModel) }}" class="{{ $settingsInputClass }}">
                                        @foreach (($transcriptionProviders[$selectedProvider]['models'] ?? []) as $model)
                                            <option value="{{ $model['id'] }}" @selected(old('speech_to_text_model', $selectedModel) === $model['id'])>
                                                {{ $model['label'] ?? $model['id'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                </label>
                            </div>
                        @else
                            <div class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm leading-6 text-black">
                                Save and test a license key before choosing hosted providers and models.
                            </div>
                        @endif

                        <div class="flex justify-end">
                            <button type="submit" data-settings-save class="h-11 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-70">Save transcription</button>
                        </div>
                    </section>

                    <section data-chat-settings-panel="resources" class="hidden space-y-5">
                        <div>
                            <p class="text-xs font-semibold uppercase text-blue-600">Resources</p>
                            <h3 class="mt-1 text-xl font-semibold text-black">CPU, RAM, and GPU</h3>
                            <p class="mt-1 text-sm leading-6 text-blue-900">{{ $resourceProfile['gpu_available'] ? $resourceProfile['gpu_name'].' is available for compatible Whisper models.' : 'No compatible Whisper GPU was detected. Offline transcription will use CPU.' }}</p>
                        </div>

                        <div data-resource-auto-summary class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-sm leading-6 text-blue-900 {{ $resourceProfile['mode'] === 'manual' ? 'hidden' : '' }}">
                            <p class="font-semibold text-blue-800">Automatic resource management is enabled.</p>
                            <p class="mt-1 text-black">
                                {{ config('app.brand_name', 'JERVA Transcriber') }} will balance transcription work across {{ $resourceProfile['auto_cpu_threads'] }} CPU threads and up to {{ $resourceProfile['auto_memory_budget_mb'] }} MB RAM.
                            </p>
                            @if ($resourceProfile['gpu_available'])
                                <p class="mt-1 text-blue-900">
                                    GPU acceleration can use up to {{ $resourceProfile['auto_gpu_vram_budget_mb'] }} MB VRAM when compatible models need it.
                                </p>
                            @endif
                        </div>

                        <div class="grid gap-4 md:grid-cols-2">
                            <label class="block">
                                <span class="text-sm font-semibold text-black">Mode</span>
                                <select name="resource_mode" data-resource-mode class="{{ $settingsInputClass }}">
                                    <option value="auto" @selected(old('resource_mode', $resourceProfile['mode']) === 'auto')>Auto</option>
                                    <option value="manual" @selected(old('resource_mode', $resourceProfile['mode']) === 'manual')>Manual</option>
                                </select>
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-black">CPU threads</span>
                                <input type="number" min="1" max="{{ $resourceProfile['max_cpu_threads'] }}" name="resource_cpu_threads" data-resource-manual value="{{ old('resource_cpu_threads', $resourceProfile['cpu_threads']) }}" class="{{ $settingsInputClass }}">
                                <span class="mt-1 block text-xs text-blue-900">Max is {{ $resourceProfile['max_cpu_threads'] }}.</span>
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-black">RAM budget MB</span>
                                <input type="number" min="{{ $resourceProfile['max_memory_budget_mb'] > 0 ? 1 : 0 }}" max="{{ $resourceProfile['max_memory_budget_mb'] }}" name="resource_memory_budget_mb" data-resource-manual value="{{ old('resource_memory_budget_mb', $resourceProfile['memory_budget_mb']) }}" class="{{ $settingsInputClass }}">
                                <span class="mt-1 block text-xs text-blue-900">Max is {{ $resourceProfile['max_memory_budget_mb'] }} MB.</span>
                            </label>

                            <label class="block">
                                <span class="text-sm font-semibold text-black">GPU VRAM budget MB</span>
                                <input type="number" min="0" max="{{ $resourceProfile['max_gpu_vram_budget_mb'] }}" name="resource_gpu_vram_budget_mb" data-resource-manual data-resource-gpu-manual data-gpu-available="{{ $resourceProfile['gpu_available'] ? 'true' : 'false' }}" value="{{ old('resource_gpu_vram_budget_mb', $resourceProfile['gpu_vram_budget_mb']) }}" @disabled(! $resourceProfile['gpu_available'] || old('resource_mode', $resourceProfile['mode']) !== 'manual') class="{{ $settingsInputClass }}">
                                <span class="mt-1 block text-xs text-blue-900">{{ $resourceProfile['gpu_available'] ? 'Max is '.$resourceProfile['max_gpu_vram_budget_mb'].' MB.' : 'CPU fallback active.' }}</span>
                            </label>
                        </div>

                        <div class="flex justify-end">
                            <button type="submit" data-settings-save class="h-11 cursor-pointer rounded-lg bg-blue-600 px-4 text-sm font-semibold text-white transition hover:bg-blue-700 disabled:cursor-not-allowed disabled:opacity-70">Save resources</button>
                        </div>
                    </section>
                </form>

                <section data-chat-settings-panel="memory" class="hidden space-y-5">
                    <div>
                        <p class="text-xs font-semibold uppercase text-blue-600">Memory</p>
                        <h3 class="mt-1 text-xl font-semibold text-black">Audio and transcript storage</h3>
                        <p class="mt-1 text-sm leading-6 text-blue-900">Clear cached files or stored content without leaving the workspace.</p>
                    </div>

                    <div class="grid gap-3 md:grid-cols-2">
                        <div class="rounded-lg border border-blue-200 p-4">
                            <p class="text-sm font-semibold text-black">Audio memory</p>
                            <p class="mt-2 text-2xl font-semibold text-blue-700">{{ $audioMemory['total']['formatted_size'] }}</p>
                            <p class="mt-1 text-sm text-blue-900">Cache: {{ $audioMemory['temporary']['formatted_size'] }} - Stored: {{ $audioMemory['stored']['formatted_size'] }}</p>
                        </div>

                        <div class="rounded-lg border border-blue-200 p-4">
                            <p class="text-sm font-semibold text-black">Transcript memory</p>
                            <p class="mt-2 text-2xl font-semibold text-blue-700">{{ $transcriptMemory['total']['formatted_size'] }}</p>
                            <p class="mt-1 text-sm text-blue-900">Raw: {{ $transcriptMemory['raw']['formatted_size'] }} - Polished: {{ $transcriptMemory['cleaned']['formatted_size'] }}</p>
                        </div>
                    </div>

                    <div class="space-y-3">
                        <form method="post" action="{{ route('settings.audio-memory.temporary.clear') }}" class="flex items-center justify-between gap-4 rounded-lg border border-blue-200 px-4 py-3">
                            @csrf
                            <input type="hidden" name="return_to" value="workspace">
                            <div>
                                <p class="text-sm font-semibold text-black">Temporary upload cache</p>
                                <p class="text-sm text-blue-900">{{ number_format($audioMemory['temporary']['sessions']) }} sessions, {{ number_format($audioMemory['temporary']['files']) }} files</p>
                            </div>
                            <button type="submit" class="h-10 cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 hover:bg-blue-100">Clear</button>
                        </form>

                        <form method="post" action="{{ route('settings.audio-memory.stored.clear') }}" class="flex items-center justify-between gap-4 rounded-lg border border-blue-200 px-4 py-3">
                            @csrf
                            <input type="hidden" name="return_to" value="workspace">
                            <div>
                                <p class="text-sm font-semibold text-black">Stored audio records</p>
                                <p class="text-sm text-blue-900">{{ number_format($audioMemory['stored']['records']) }} records with audio data</p>
                            </div>
                            <button type="submit" class="h-10 cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 hover:bg-blue-100" onclick="return confirm('Clear stored audio data while keeping transcript text?')">Clear</button>
                        </form>

                        <form method="post" action="{{ route('settings.transcript-memory.clear') }}" class="flex items-center justify-between gap-4 rounded-lg border border-blue-200 px-4 py-3">
                            @csrf
                            <input type="hidden" name="return_to" value="workspace">
                            <div>
                                <p class="text-sm font-semibold text-black">Transcript text</p>
                                <p class="text-sm text-blue-900">{{ number_format($transcriptMemory['raw']['records'] + $transcriptMemory['cleaned']['records']) }} transcript records</p>
                            </div>
                            <button type="submit" class="h-10 cursor-pointer rounded-lg border border-blue-200 bg-blue-50 px-3 text-sm font-semibold text-blue-700 hover:bg-blue-100" onclick="return confirm('Clear all transcript text while keeping stored audio records?')">Clear</button>
                        </form>

                        <form method="post" action="{{ route('settings.audio-memory.all.clear') }}" class="flex items-center justify-between gap-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3">
                            @csrf
                            <input type="hidden" name="return_to" value="workspace">
                            <div>
                                <p class="text-sm font-semibold text-black">All audio data</p>
                                <p class="text-sm text-blue-900">Clear temporary files and stored audio bytes.</p>
                            </div>
                            <button type="submit" class="h-10 cursor-pointer rounded-lg bg-blue-600 px-3 text-sm font-semibold text-white hover:bg-blue-700" onclick="return confirm('Clear all audio data while keeping transcript text?')">Clear all</button>
                        </form>
                    </div>
                </section>
            </div>
        </div>
    </div>
</div>
