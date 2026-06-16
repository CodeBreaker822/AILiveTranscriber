<?php

namespace App\Http\Controllers;

use App\Services\AppSettingsService;
use App\Services\ProviderApiTestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    public function edit(AppSettingsService $settings): View
    {
        $settings->ensureFixedGeminiSettings();

        $elevenLabsStatus = $settings->providerStatus('elevenlabs');
        $geminiStatus = $settings->providerStatus('gemini');

        return view('pages.settings', [
            'hasElevenLabsApiKey' => $settings->hasElevenLabsApiKey(),
            'hasGeminiApiKey' => $settings->hasGeminiApiKey(),
            'elevenLabsApiKey' => $settings->elevenLabsApiKey(),
            'geminiApiKey' => $settings->geminiApiKey(),
            'pageStatusLabel' => $elevenLabsStatus['status'] === 'connected' ? 'Ready' : 'Needs ElevenLabs',
            'providers' => [
                $this->providerCard(
                    'ElevenLabs',
                    $elevenLabsStatus,
                ),
                $this->providerCard(
                    'Gemini',
                    $geminiStatus,
                ),
            ],
            'geminiModel' => $settings->geminiModel(),
        ]);
    }

    public function update(
        Request $request,
        AppSettingsService $settings,
        ProviderApiTestService $tester,
    ): RedirectResponse
    {
        if (! $settings->storageIsReady()) {
            return back()->withErrors([
                'settings' => 'Settings storage is not ready. Please run the database migration first.',
            ]);
        }

        $validated = $request->validate([
            'elevenlabs_api_key' => ['nullable', 'string', 'max:1000'],
            'gemini_api_key' => ['nullable', 'string', 'max:1000'],
        ]);

        $elevenLabsApiKey = trim((string) ($validated['elevenlabs_api_key'] ?? ''));
        $geminiApiKey = trim((string) ($validated['gemini_api_key'] ?? ''));

        if ($elevenLabsApiKey === '' && $geminiApiKey === '') {
            return back()
                ->withErrors(['api_keys' => 'Paste at least one API key to save.'])
                ->withInput();
        }

        if ($elevenLabsApiKey !== '') {
            $settings->setElevenLabsApiKey($elevenLabsApiKey);
        }

        if ($geminiApiKey !== '') {
            $settings->setGeminiApiKey($geminiApiKey);
        }

        $settings->ensureFixedGeminiSettings();

        if ($settings->hasElevenLabsApiKey()) {
            $settings->setProviderStatus(
                'elevenlabs',
                $tester->testElevenLabs($settings->elevenLabsApiKey() ?? ''),
            );
        } else {
            $settings->setProviderStatus('elevenlabs', [
                'status' => 'not_connected',
                'message' => 'ElevenLabs API key is not configured.',
            ]);
        }

        if ($settings->hasGeminiApiKey()) {
            $settings->setProviderStatus(
                'gemini',
                $tester->testGemini($settings->geminiApiKey() ?? '', $settings->geminiModel()),
            );
        } else {
            $settings->setProviderStatus('gemini', [
                'status' => 'not_connected',
                'message' => 'Gemini API key is not configured.',
            ]);
        }

        return redirect()
            ->route('settings.edit')
            ->with('status', 'API settings saved and tested.');
    }

    private function providerCard(string $name, array $status): array
    {
        $statusKey = in_array($status['status'] ?? null, ['connected', 'not_connected', 'invalid'], true)
            ? $status['status']
            : 'not_connected';
        $meta = $this->statusMeta($statusKey);

        return [
            'name' => $name,
            'label' => $meta['label'],
            'classes' => $meta['classes'],
            'dot' => $meta['dot'],
            'message' => $status['message'] ?? '',
            'checked_at' => $status['checked_at'] ?? null,
            'details' => $this->formatDetails($status['details'] ?? []),
        ];
    }

    private function statusMeta(string $status): array
    {
        return match ($status) {
            'connected' => [
                'label' => 'Connected',
                'classes' => 'border-emerald-300/25 bg-emerald-300/10 text-emerald-100',
                'dot' => 'bg-emerald-300',
            ],
            'invalid' => [
                'label' => 'Invalid',
                'classes' => 'border-rose-400/25 bg-rose-400/10 text-rose-100',
                'dot' => 'bg-rose-300',
            ],
            default => [
                'label' => 'Not connected',
                'classes' => 'border-slate-500/25 bg-white/[0.03] text-slate-200',
                'dot' => 'bg-slate-400',
            ],
        };
    }

    private function formatDetails(array $details): array
    {
        return collect($details)
            ->map(fn ($value, $key): array => [
                'label' => str_replace('_', ' ', (string) $key),
                'value' => $this->formatDetailValue($value),
            ])
            ->values()
            ->all();
    }

    private function formatDetailValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }

        if (is_int($value) || is_float($value)) {
            return number_format($value);
        }

        if (is_numeric($value)) {
            return number_format((float) $value);
        }

        return (string) $value;
    }
}
