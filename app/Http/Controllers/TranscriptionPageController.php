<?php

namespace App\Http\Controllers;

use App\Services\Config\AppSettingsService;
use App\Services\Audio\AudioMemoryService;
use App\Services\Speech\OfflineWhisperModelService;
use App\Services\Transcripts\TranscriptMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

class TranscriptionPageController extends Controller
{
    public function live(AppSettingsService $settings, OfflineWhisperModelService $offlineModels): View|RedirectResponse
    {
        if (config('app.edition') === 'jerva') {
            return redirect()->route('transcription.workspace');
        }

        return view('astra.pages.live', $this->transcriptionControls($settings, $offlineModels));
    }

    public function upload(AppSettingsService $settings, OfflineWhisperModelService $offlineModels): View|RedirectResponse
    {
        if (config('app.edition') === 'jerva') {
            return redirect()->route('transcription.workspace');
        }

        return view('astra.pages.upload', $this->transcriptionControls($settings, $offlineModels));
    }

    public function workspace(
        AppSettingsService $settings,
        OfflineWhisperModelService $offlineModels,
        AudioMemoryService $audioMemory,
        TranscriptMemoryService $transcriptMemory,
    ): View|RedirectResponse
    {
        if (config('app.edition') !== 'jerva' && ! config('app.desktop_dev')) {
            return redirect()->route('transcription.live');
        }

        return view('jerva.pages.workspace', [
            ...$this->transcriptionControls($settings, $offlineModels),
            ...$this->workspaceSettings($settings, $audioMemory, $transcriptMemory),
        ]);
    }

    public function desktopLoading(): View
    {
        return view(config('app.edition') === 'jerva'
            ? 'jerva.pages.desktop-loading'
            : 'astra.pages.desktop-loading');
    }

    public function desktopAssetsReady(): JsonResponse
    {
        if (! config('app.desktop_dev')) {
            return response()->json(['ready' => true]);
        }

        try {
            $vite = Http::timeout(2)
                ->connectTimeout(1)
                ->get('http://127.0.0.1:5173/@vite/client');
        } catch (Throwable) {
            return response()->json(['ready' => false], 503);
        }

        return $vite->ok()
            ? response()->json(['ready' => true])
            : response()->json(['ready' => false], 503);
    }

    /**
     * @return array<string, mixed>
     */
    private function transcriptionControls(AppSettingsService $settings, OfflineWhisperModelService $offlineModels): array
    {
        return [
            'languageOptions' => $settings->speechToTextLanguageOptions(),
            'whisperModels' => $offlineModels->catalog(),
            'audioChunkSeconds' => $settings->audioChunkSeconds(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function workspaceSettings(
        AppSettingsService $settings,
        AudioMemoryService $audioMemory,
        TranscriptMemoryService $transcriptMemory,
    ): array {
        $provider = $settings->speechToTextProvider();
        $transcriptionProviders = $settings->transcriptionProviderOptions();

        return [
            'apiBaseUrl' => $settings->apiBaseUrl(),
            'hasLicenseKey' => $settings->hasLicenseKey(),
            'licenseKeySuffix' => $settings->licenseKeySuffix(),
            'licenseStatusLabel' => $settings->licenseStatusLabel(),
            'licenseStatusMessage' => $settings->licenseStatusMessage(),
            'licenseRefreshError' => null,
            'transcriptionProviders' => $transcriptionProviders,
            'providerPayload' => $this->providerPayload($transcriptionProviders),
            'selectedProvider' => $provider,
            'selectedModel' => $settings->speechToTextModel($provider),
            'resourceProfile' => $settings->resourceProfile(),
            'audioMemory' => $audioMemory->snapshot(),
            'transcriptMemory' => $transcriptMemory->snapshot(),
        ];
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
}
