<?php

namespace App\Http\Controllers;

use App\Services\AppSettingsService;
use App\Services\ProviderApiTestService;
use App\Services\ServiceUserMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SettingsController extends Controller
{
    private const ELEVENLABS_API_KEYS_URL = 'https://elevenlabs.io/app/developers/api-keys';

    private const ELEVENLABS_AUTH_DOCS_URL = 'https://elevenlabs.io/docs/api-reference/authentication';

    private const DEEPGRAM_API_KEYS_URL = 'https://console.deepgram.com/project/keys';

    private const DEEPGRAM_LISTEN_DOCS_URL = 'https://developers.deepgram.com/reference/speech-to-text/listen-pre-recorded';

    private const GEMINI_API_KEYS_URL = 'https://aistudio.google.com/apikey';

    private const GEMINI_API_KEY_DOCS_URL = 'https://ai.google.dev/gemini-api/docs/api-key';

    public function edit(AppSettingsService $settings): View
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
            $settings->setProviderStatus(
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
            $settings->setProviderStatus(
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
            $settings->setProviderStatus(
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
                'purpose' => 'Used by AITranscriber to turn uploaded or recorded audio into raw transcript text.',
                'key_url' => self::ELEVENLABS_API_KEYS_URL,
                'docs_url' => self::ELEVENLABS_AUTH_DOCS_URL,
                'steps' => [
                    'Open the ElevenLabs API keys page and sign in to your ElevenLabs account.',
                    'Create a new API key from the developer API keys area.',
                    'Copy the generated key immediately and paste it into the ElevenLabs API key field in AITranscriber.',
                    'Save the settings so AITranscriber can test the key before transcription.',
                ],
                'notes' => [
                    'ElevenLabs is required for transcription.',
                    'Keep the key private. It controls access to your ElevenLabs usage quota.',
                ],
            ],
            [
                'id' => 'deepgram',
                'name' => 'Deepgram',
                'purpose' => 'Used by AITranscriber as an alternative provider for turning uploaded or recorded audio into raw transcript text.',
                'key_url' => self::DEEPGRAM_API_KEYS_URL,
                'docs_url' => self::DEEPGRAM_LISTEN_DOCS_URL,
                'steps' => [
                    'Open the Deepgram API keys page and sign in to your Deepgram account.',
                    'Create a project API key with speech-to-text access.',
                    'Copy the generated key and paste it into the Deepgram API key field in AITranscriber.',
                    'Save the settings and select Deepgram as the main speech-to-text provider when you want new transcripts to use it.',
                ],
                'notes' => [
                    'Deepgram is optional unless selected as the main speech-to-text provider.',
                    'AITranscriber uses Deepgram Nova-3 for pre-recorded speech-to-text.',
                ],
            ],
            [
                'id' => 'gemini',
                'name' => 'Gemini',
                'purpose' => 'Used by AITranscriber only when you want the raw transcript cleaned or furnished.',
                'key_url' => self::GEMINI_API_KEYS_URL,
                'docs_url' => self::GEMINI_API_KEY_DOCS_URL,
                'steps' => [
                    'Open Google AI Studio API keys and sign in with your Google account.',
                    'Create an API key from the API keys page.',
                    'Copy the generated key and paste it into the Gemini API key field in AITranscriber.',
                    'Save the settings so AITranscriber can test the key. You may leave Gemini empty if you only need raw transcripts.',
                ],
                'notes' => [
                    'Gemini is optional.',
                    'The Gemini model is fixed by AITranscriber, so users only need to provide the key.',
                ],
            ],
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
