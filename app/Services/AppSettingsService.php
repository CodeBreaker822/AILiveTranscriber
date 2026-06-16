<?php

namespace App\Services;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Schema;
use Throwable;

class AppSettingsService
{
    public const ELEVENLABS_API_KEY = 'elevenlabs.api_key';

    public const DEEPGRAM_API_KEY = 'deepgram.api_key';

    public const SPEECH_TO_TEXT_PROVIDER = 'speech_to_text.provider';

    public const DEEPGRAM_MODEL = 'deepgram.model';

    public const GEMINI_API_KEY = 'gemini.api_key';

    public const GEMINI_MODEL = 'gemini.model';

    public const GEMINI_TIMEOUT = 'gemini.timeout';

    public const GEMINI_MAX_RETRIES = 'gemini.max_retries';

    public const ELEVENLABS_STATUS = 'elevenlabs.status';

    public const DEEPGRAM_STATUS = 'deepgram.status';

    public const GEMINI_STATUS = 'gemini.status';

    private const DEFAULT_SPEECH_TO_TEXT_PROVIDER = 'elevenlabs';

    private const FIXED_DEEPGRAM_MODEL = 'nova-3';

    private const FIXED_GEMINI_MODEL = 'gemini-3.1-flash-lite';

    private const FIXED_GEMINI_TIMEOUT = '30';

    private const FIXED_GEMINI_MAX_RETRIES = '3';

    public function elevenLabsApiKey(): ?string
    {
        return $this->get(self::ELEVENLABS_API_KEY);
    }

    public function hasElevenLabsApiKey(): bool
    {
        $apiKey = $this->elevenLabsApiKey();

        return is_string($apiKey) && trim($apiKey) !== '';
    }

    public function setElevenLabsApiKey(string $apiKey): void
    {
        $this->set(self::ELEVENLABS_API_KEY, trim($apiKey));
    }

    public function deepgramApiKey(): ?string
    {
        return $this->get(self::DEEPGRAM_API_KEY);
    }

    public function hasDeepgramApiKey(): bool
    {
        $apiKey = $this->deepgramApiKey();

        return is_string($apiKey) && trim($apiKey) !== '';
    }

    public function setDeepgramApiKey(string $apiKey): void
    {
        $this->set(self::DEEPGRAM_API_KEY, trim($apiKey));
    }

    public function speechToTextProvider(): string
    {
        $provider = $this->get(self::SPEECH_TO_TEXT_PROVIDER, self::DEFAULT_SPEECH_TO_TEXT_PROVIDER);

        return in_array($provider, ['elevenlabs', 'deepgram'], true)
            ? $provider
            : self::DEFAULT_SPEECH_TO_TEXT_PROVIDER;
    }

    public function setSpeechToTextProvider(string $provider): void
    {
        if (! in_array($provider, ['elevenlabs', 'deepgram'], true)) {
            $provider = self::DEFAULT_SPEECH_TO_TEXT_PROVIDER;
        }

        $this->set(self::SPEECH_TO_TEXT_PROVIDER, $provider);
    }

    public function geminiApiKey(): ?string
    {
        return $this->get(self::GEMINI_API_KEY);
    }

    public function hasGeminiApiKey(): bool
    {
        $apiKey = $this->geminiApiKey();

        return is_string($apiKey) && trim($apiKey) !== '';
    }

    public function setGeminiApiKey(string $apiKey): void
    {
        $this->set(self::GEMINI_API_KEY, trim($apiKey));
    }

    public function storageIsReady(): bool
    {
        return $this->settingsTableExists();
    }

    public function providerStatus(string $provider): array
    {
        $key = match ($provider) {
            'elevenlabs' => self::ELEVENLABS_STATUS,
            'deepgram' => self::DEEPGRAM_STATUS,
            'gemini' => self::GEMINI_STATUS,
            default => null,
        };

        if ($key === null) {
            return $this->notConnectedStatus('This provider is not set up yet.');
        }

        $status = $this->get($key);

        if (! is_string($status) || trim($status) === '') {
            return $this->notConnectedStatus('This provider has not been tested yet.');
        }

        $decoded = json_decode($status, true);

        if (! is_array($decoded)) {
            return $this->notConnectedStatus('This provider has not been tested yet.');
        }

        return [
            'status' => in_array($decoded['status'] ?? null, ['connected', 'not_connected', 'invalid'], true)
                ? $decoded['status']
                : 'not_connected',
            'message' => (string) ($decoded['message'] ?? ''),
            'checked_at' => $decoded['checked_at'] ?? null,
            'details' => is_array($decoded['details'] ?? null) ? $decoded['details'] : [],
        ];
    }

    public function setProviderStatus(string $provider, array $status): void
    {
        $key = match ($provider) {
            'elevenlabs' => self::ELEVENLABS_STATUS,
            'deepgram' => self::DEEPGRAM_STATUS,
            'gemini' => self::GEMINI_STATUS,
            default => null,
        };

        if ($key === null) {
            return;
        }

        $this->set($key, json_encode([
            'status' => $status['status'] ?? 'not_connected',
            'message' => $status['message'] ?? '',
            'checked_at' => now()->toDateTimeString(),
            'details' => $status['details'] ?? [],
        ]));
    }

    public function geminiModel(): string
    {
        return $this->fixedSetting(self::GEMINI_MODEL, self::FIXED_GEMINI_MODEL);
    }

    public function deepgramModel(): string
    {
        return $this->fixedSetting(self::DEEPGRAM_MODEL, self::FIXED_DEEPGRAM_MODEL);
    }

    public function geminiTimeout(): int
    {
        return (int) $this->fixedSetting(self::GEMINI_TIMEOUT, self::FIXED_GEMINI_TIMEOUT);
    }

    public function geminiMaxRetries(): int
    {
        return (int) $this->fixedSetting(self::GEMINI_MAX_RETRIES, self::FIXED_GEMINI_MAX_RETRIES);
    }

    public function ensureFixedGeminiSettings(): void
    {
        $this->set(self::GEMINI_MODEL, self::FIXED_GEMINI_MODEL);
        $this->set(self::GEMINI_TIMEOUT, self::FIXED_GEMINI_TIMEOUT);
        $this->set(self::GEMINI_MAX_RETRIES, self::FIXED_GEMINI_MAX_RETRIES);
    }

    public function ensureFixedSpeechToTextSettings(): void
    {
        if (! in_array($this->get(self::SPEECH_TO_TEXT_PROVIDER), ['elevenlabs', 'deepgram'], true)) {
            $this->set(self::SPEECH_TO_TEXT_PROVIDER, self::DEFAULT_SPEECH_TO_TEXT_PROVIDER);
        }

        $this->set(self::DEEPGRAM_MODEL, self::FIXED_DEEPGRAM_MODEL);
    }

    public function get(string $key, ?string $default = null): ?string
    {
        if (! $this->settingsTableExists()) {
            return $default;
        }

        $setting = $this->setting($key);

        if (! $setting) {
            return $default;
        }

        try {
            if ($setting->value === null) {
                return $default;
            }

            return (string) $setting->value;
        } catch (Throwable) {
            return $default;
        }
    }

    public function set(string $key, ?string $value): void
    {
        if (! $this->settingsTableExists()) {
            return;
        }

        AppSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'is_encrypted' => true],
        );
    }

    private function settingsTableExists(): bool
    {
        try {
            return Schema::hasTable('app_settings');
        } catch (Throwable) {
            return false;
        }
    }

    private function fixedSetting(string $key, string $value): string
    {
        if ($this->get($key, $value) !== $value) {
            $this->set($key, $value);
        }

        return $value;
    }

    private function setting(string $key): ?AppSetting
    {
        try {
            return AppSetting::query()->where('key', $key)->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function notConnectedStatus(string $message): array
    {
        return [
            'status' => 'not_connected',
            'message' => $message,
            'checked_at' => null,
            'details' => [],
        ];
    }
}
