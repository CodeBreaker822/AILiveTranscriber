<?php

namespace App\Http\Controllers;

use App\Exceptions\SpeechToTextException;
use App\Services\Config\AppSettingsService;
use App\Services\Audio\AudioMemoryService;
use App\Services\HostedApi\HostedTranscriptionApiService;
use App\Services\Transcripts\TranscriptMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(
        AppSettingsService $settings,
        HostedTranscriptionApiService $api,
        AudioMemoryService $audioMemory,
        TranscriptMemoryService $transcriptMemory,
    ): View|RedirectResponse {
        if (config('app.edition') === 'jerva') {
            return redirect()->route('transcription.workspace');
        }

        $licenseRefreshError = null;

        if ($this->shouldRefreshLicenseCapabilities($settings)) {
            try {
                $this->refreshLicenseCapabilities($settings, $api, $settings->licenseKey() ?? '');
            } catch (SpeechToTextException $exception) {
                $licenseRefreshError = $exception->getMessage();
            }
        }

        $provider = $settings->speechToTextProvider();
        $transcriptionProviders = $settings->transcriptionProviderOptions();

        return view('astra.pages.settings', [
            'apiBaseUrl' => $settings->apiBaseUrl(),
            'hasLicenseKey' => $settings->hasLicenseKey(),
            'licenseKeySuffix' => $settings->licenseKeySuffix(),
            'licenseStatusLabel' => $settings->licenseStatusLabel(),
            'licenseStatusMessage' => $settings->licenseStatusMessage(),
            'licenseRefreshError' => $licenseRefreshError,
            'transcriptionProviders' => $transcriptionProviders,
            'providerPayload' => $this->providerPayload($transcriptionProviders),
            'selectedProvider' => $provider,
            'selectedModel' => $settings->speechToTextModel($provider),
            'resourceProfile' => $settings->resourceProfile(),
            'audioMemory' => $audioMemory->snapshot(),
            'transcriptMemory' => $transcriptMemory->snapshot(),
        ]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $providers
     * @return array<string, array{models: array<int, array{id: string, label: string}>}>
     */
    private function providerPayload(array $providers): array
    {
        return collect($providers)
            ->mapWithKeys(fn (array $provider, string $key): array => [
                $key => [
                    'models' => collect($provider['models'] ?? [])
                        ->filter(fn ($model): bool => is_array($model) && filled($model['id'] ?? null))
                        ->map(fn (array $model): array => [
                            'id' => (string) $model['id'],
                            'label' => (string) ($model['label'] ?? $model['id']),
                        ])
                        ->values()
                        ->all(),
                ],
            ])
            ->all();
    }

    public function help(): View|RedirectResponse
    {
        if (config('app.edition') === 'jerva') {
            return redirect()->route('transcription.workspace');
        }

        return view('astra.pages.api-key-help', [
            'providers' => [],
        ]);
    }

    public function update(
        Request $request,
        AppSettingsService $settings,
        HostedTranscriptionApiService $api,
    ): RedirectResponse|JsonResponse {
        if (! $settings->storageIsReady()) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Settings storage is not ready. Please run the database migration first.',
                ], 422);
            }

            return back()->withErrors([
                'settings' => 'Settings storage is not ready. Please run the database migration first.',
            ]);
        }

        $resourceProfile = $settings->resourceProfile();
        $maxCpuThreads = max(1, (int) $resourceProfile['max_cpu_threads']);
        $maxMemoryBudgetMb = max(0, (int) $resourceProfile['max_memory_budget_mb']);
        $maxGpuVramBudgetMb = max(0, (int) $resourceProfile['max_gpu_vram_budget_mb']);
        $memoryRules = $maxMemoryBudgetMb > 0
            ? ['nullable', 'integer', 'min:1', 'max:'.$maxMemoryBudgetMb]
            : ['nullable', 'integer', 'min:0', 'max:0'];
        $gpuVramRules = $maxGpuVramBudgetMb > 0
            ? ['nullable', 'integer', 'min:0', 'max:'.$maxGpuVramBudgetMb]
            : ['nullable', 'integer', 'min:0', 'max:0'];

        $validated = $request->validate([
            'api_base_url' => ['required', 'string', 'max:255'],
            'license_key' => [$settings->hasLicenseKey() ? 'nullable' : 'required', 'string', 'max:2000'],
            'speech_to_text_provider' => ['nullable', 'string', 'max:80'],
            'speech_to_text_model' => ['nullable', 'string', 'max:120'],
            'resource_mode' => ['nullable', 'string', 'in:auto,manual'],
            'resource_cpu_threads' => ['nullable', 'integer', 'min:1', 'max:'.$maxCpuThreads],
            'resource_memory_budget_mb' => $memoryRules,
            'resource_gpu_vram_budget_mb' => $gpuVramRules,
        ]);

        $settings->setApiBaseUrl((string) $validated['api_base_url']);
        $licenseKey = trim((string) ($validated['license_key'] ?? ''));

        if ($licenseKey !== '') {
            $settings->setLicenseKey($licenseKey);
        } else {
            $licenseKey = $settings->licenseKey() ?? '';
        }

        try {
            $this->refreshLicenseCapabilities($settings, $api, $licenseKey);
        } catch (SpeechToTextException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'errors' => [
                        'license_key' => [$exception->getMessage()],
                    ],
                ], 422);
            }

            return back()
                ->withInput()
                ->withErrors(['license_key' => $exception->getMessage()]);
        }

        $this->selectAvailableProviderAndModel(
            $settings,
            (string) ($validated['speech_to_text_provider'] ?? ''),
            (string) ($validated['speech_to_text_model'] ?? ''),
        );
        $settings->setResourceProfile(
            (string) ($validated['resource_mode'] ?? 'auto'),
            (int) ($validated['resource_cpu_threads'] ?? $resourceProfile['auto_cpu_threads']),
            (int) ($validated['resource_memory_budget_mb'] ?? $resourceProfile['auto_memory_budget_mb']),
            (int) ($validated['resource_gpu_vram_budget_mb'] ?? $resourceProfile['auto_gpu_vram_budget_mb']),
        );

        $message = 'Settings saved.';

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $message,
                'data' => $this->settingsPayload($settings),
            ]);
        }

        return redirect()
            ->route($request->string('return_to')->toString() === 'workspace' ? 'transcription.workspace' : 'settings.edit')
            ->with('status', $message);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsPayload(AppSettingsService $settings): array
    {
        $provider = $settings->speechToTextProvider();
        $providers = $settings->transcriptionProviderOptions();

        return [
            'api_base_url' => $settings->apiBaseUrl(),
            'has_license_key' => $settings->hasLicenseKey(),
            'license_key_suffix' => $settings->licenseKeySuffix(),
            'license_status_label' => $settings->licenseStatusLabel(),
            'license_status_message' => $settings->licenseStatusMessage(),
            'transcription_providers' => array_values($providers),
            'provider_payload' => $this->providerPayload($providers),
            'selected_provider' => $provider,
            'selected_model' => $settings->speechToTextModel($provider),
            'resource_profile' => $settings->resourceProfile(),
        ];
    }

    private function shouldRefreshLicenseCapabilities(AppSettingsService $settings): bool
    {
        return $settings->hasLicenseKey()
            && ($settings->licenseStatus() === [] || $settings->transcriptionProviderOptions() === []);
    }

    private function refreshLicenseCapabilities(
        AppSettingsService $settings,
        HostedTranscriptionApiService $api,
        string $licenseKey,
    ): void {
        $status = $api->licenseStatus($licenseKey);

        $settings->setLicenseStatus($status);
        $this->selectAvailableProviderAndModel($settings);
    }

    private function selectAvailableProviderAndModel(
        AppSettingsService $settings,
        ?string $requestedProvider = null,
        ?string $requestedModel = null,
    ): void
    {
        $providers = $settings->transcriptionProviderOptions();
        $provider = trim((string) ($requestedProvider ?: $settings->speechToTextProvider()));

        if (! isset($providers[$provider])) {
            $provider = (string) (array_key_first($providers) ?? '');
        }

        if ($provider !== '') {
            $settings->setSpeechToTextProvider($provider);
        }

        $models = $settings->transcriptionModelOptions($provider);
        $model = trim((string) ($requestedModel ?: $settings->speechToTextModel($provider)));

        if (! isset($models[$model])) {
            $model = (string) (array_key_first($models) ?? '');
        }

        if ($model !== '') {
            $settings->setSpeechToTextModel($model);
        }
    }
}
