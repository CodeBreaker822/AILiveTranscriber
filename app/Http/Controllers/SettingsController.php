<?php

namespace App\Http\Controllers;

use App\Services\AppSettingsService;
use App\Services\AudioMemoryService;
use App\Services\ProviderApiTestService;
use App\Services\ServiceUserMessage;
use App\Services\TranscriptMemoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const ELEVENLABS_API_KEYS_URL = 'https://elevenlabs.io/app/developers/api-keys';

    private const DEEPGRAM_API_KEYS_URL = 'https://console.deepgram.com/project/keys';

    private const GEMINI_API_KEYS_URL = 'https://aistudio.google.com/apikey';

    public function edit(
        AppSettingsService $settings,
        AudioMemoryService $audioMemory,
        TranscriptMemoryService $transcriptMemory,
    ): View
    {
        $settings->ensureFixedSpeechToTextSettings();
        $settings->ensureFixedGeminiSettings();

        $elevenLabsStatus = $settings->providerStatus('elevenlabs');
        $deepgramStatus = $settings->providerStatus('deepgram');
        $geminiStatus = $settings->providerStatus('gemini');
        $speechToTextProvider = $settings->speechToTextProvider();
        $selectedSpeechStatus = $speechToTextProvider === 'deepgram' ? $deepgramStatus : $elevenLabsStatus;

        return view('pages.settings', [
            'hasElevenLabsApiKey' => $settings->hasElevenLabsApiKey(),
            'hasDeepgramApiKey' => $settings->hasDeepgramApiKey(),
            'hasGeminiApiKey' => $settings->hasGeminiApiKey(),
            'elevenLabsApiKey' => $settings->elevenLabsApiKey(),
            'deepgramApiKey' => $settings->deepgramApiKey(),
            'geminiApiKey' => $settings->geminiApiKey(),
            'speechToTextProvider' => $speechToTextProvider,
            'apiKeyLinks' => $this->apiKeyLinks(),
            'pageStatusLabel' => $selectedSpeechStatus['status'] === 'connected'
                ? 'Ready'
                : 'Needs '.($speechToTextProvider === 'deepgram' ? 'Deepgram' : 'ElevenLabs'),
            'providers' => [
                $this->providerCard(
                    'ElevenLabs',
                    $elevenLabsStatus,
                ),
                $this->providerCard(
                    'Deepgram',
                    $deepgramStatus,
                ),
                $this->providerCard(
                    'Gemini',
                    $geminiStatus,
                ),
            ],
            'geminiModel' => $settings->geminiModel(),
            'deepgramModel' => $settings->deepgramModel(),
            'audioMemory' => $audioMemory->snapshot(),
            'transcriptMemory' => $transcriptMemory->snapshot(),
        ]);
    }

    public function help(): View
    {
        return view('pages.api-key-help', [
            'providers' => $this->apiKeyInstructions(),
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
            'speech_to_text_provider' => ['required', 'in:elevenlabs,deepgram'],
            'elevenlabs_api_key' => ['nullable', 'string', 'max:1000'],
            'deepgram_api_key' => ['nullable', 'string', 'max:1000'],
            'gemini_api_key' => ['nullable', 'string', 'max:1000'],
        ]);

        $settings->setSpeechToTextProvider((string) $validated['speech_to_text_provider']);

        $elevenLabsApiKey = trim((string) ($validated['elevenlabs_api_key'] ?? ''));
        $deepgramApiKey = trim((string) ($validated['deepgram_api_key'] ?? ''));
        $geminiApiKey = trim((string) ($validated['gemini_api_key'] ?? ''));

        if ($elevenLabsApiKey !== '') {
            $settings->setElevenLabsApiKey($elevenLabsApiKey);
        }

        if ($deepgramApiKey !== '') {
            $settings->setDeepgramApiKey($deepgramApiKey);
        }

        $settings->ensureFixedSpeechToTextSettings();

        if ($geminiApiKey !== '') {
            $settings->setGeminiApiKey($geminiApiKey);
        }

        $settings->ensureFixedGeminiSettings();

        if ($settings->hasElevenLabsApiKey()) {
            $this->setProviderStatusWithoutPoisoningConnection(
                $settings,
                'elevenlabs',
                $tester->testElevenLabs($settings->elevenLabsApiKey() ?? ''),
            );
        } else {
            $settings->setProviderStatus('elevenlabs', [
                'status' => 'not_connected',
                'message' => ServiceUserMessage::missingApiKey('ElevenLabs'),
            ]);
        }

        if ($settings->hasDeepgramApiKey()) {
            $this->setProviderStatusWithoutPoisoningConnection(
                $settings,
                'deepgram',
                $tester->testDeepgram($settings->deepgramApiKey() ?? '', $settings->deepgramModel()),
            );
        } else {
            $settings->setProviderStatus('deepgram', [
                'status' => 'not_connected',
                'message' => ServiceUserMessage::missingApiKey('Deepgram'),
            ]);
        }

        if ($settings->hasGeminiApiKey()) {
            $this->setProviderStatusWithoutPoisoningConnection(
                $settings,
                'gemini',
                $tester->testGemini($settings->geminiApiKey() ?? '', $settings->geminiModel()),
            );
        } else {
            $settings->setProviderStatus('gemini', [
                'status' => 'not_connected',
                'message' => ServiceUserMessage::missingApiKey('Gemini'),
            ]);
        }

        return redirect()
            ->route('settings.edit')
            ->with('status', 'API settings saved and tested.');
    }

    private function setProviderStatusWithoutPoisoningConnection(
        AppSettingsService $settings,
        string $provider,
        array $status,
    ): void {
        $previousStatus = $settings->providerStatus($provider);
        $message = (string) ($status['message'] ?? '');

        if (
            ($previousStatus['status'] ?? null) === 'connected'
            && ($status['status'] ?? null) === 'not_connected'
            && str_contains($message, 'could not reach')
        ) {
            return;
        }

        $settings->setProviderStatus($provider, $status);
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

    private function apiKeyLinks(): array
    {
        return [
            'elevenlabs' => [
                'key_url' => self::ELEVENLABS_API_KEYS_URL,
                'help_url' => route('settings.api-key-help').'#elevenlabs',
            ],
            'deepgram' => [
                'key_url' => self::DEEPGRAM_API_KEYS_URL,
                'help_url' => route('settings.api-key-help').'#deepgram',
            ],
            'gemini' => [
                'key_url' => self::GEMINI_API_KEYS_URL,
                'help_url' => route('settings.api-key-help').'#gemini',
            ],
        ];
    }

    private function apiKeyInstructions(): array
    {
        return [
            [
                'id' => 'elevenlabs',
                'name' => 'ElevenLabs',
                'key_url' => self::ELEVENLABS_API_KEYS_URL,
                'steps' => [
                    'Open the ElevenLabs key page and sign in or create an account.',
                    'Select Create API Key and give the key a name you will recognize.',
                    'Copy the new key and return to AITranscriber Settings.',
                    'Paste it into the ElevenLabs API key field, then select Save and test.',
                ],
            ],
            [
                'id' => 'deepgram',
                'name' => 'Deepgram',
                'key_url' => self::DEEPGRAM_API_KEYS_URL,
                'steps' => [
                    'Open the Deepgram key page and sign in or create an account.',
                    'Choose a project, then select Create a New API Key.',
                    'Copy the new key and return to AITranscriber Settings.',
                    'Paste it into the Deepgram API key field, then select Save and test.',
                ],
            ],
            [
                'id' => 'gemini',
                'name' => 'Gemini',
                'key_url' => self::GEMINI_API_KEYS_URL,
                'steps' => [
                    'Open the Google AI Studio key page and sign in with your Google account.',
                    'Select Create API key and choose a Google Cloud project when prompted.',
                    'Copy the new key and return to AITranscriber Settings.',
                    'Paste it into the Gemini API key field, then select Save and test.',
                ],
            ],
        ];
    }

    private function statusMeta(string $status): array
    {
        return match ($status) {
            'connected' => [
                'label' => 'Connected',
                'classes' => 'border-white/10 bg-white/[0.03] text-slate-100',
                'dot' => 'bg-emerald-300',
            ],
            'invalid' => [
                'label' => 'Invalid',
                'classes' => 'border-white/10 bg-white/[0.03] text-slate-100',
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
