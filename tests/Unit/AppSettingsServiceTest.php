<?php

namespace Tests\Unit;

use App\Services\AppSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppSettingsServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_stores_editable_api_base_url_with_https_default(): void
    {
        $settings = app(AppSettingsService::class);

        $this->assertSame('https://dilgaims.site/api', $settings->apiBaseUrl());

        $settings->setApiBaseUrl('new-domain.example/api/');

        $this->assertSame('https://new-domain.example/api', $settings->apiBaseUrl());
    }

    public function test_it_returns_only_the_license_key_suffix_for_display(): void
    {
        $settings = app(AppSettingsService::class);
        $settings->setLicenseKey('is_license_1234567890');

        $this->assertSame('67890', $settings->licenseKeySuffix());
    }

    public function test_it_uses_server_returned_provider_model_and_language_options(): void
    {
        $settings = app(AppSettingsService::class);

        $settings->setLicenseStatus([
            'providers' => [
                'transcription' => [
                    [
                        'provider' => 'deepgram',
                        'name' => 'Deepgram',
                        'configured' => true,
                        'enabled' => true,
                        'connected' => true,
                        'models' => [
                            [
                                'id' => 'nova-3',
                                'label' => 'Nova-3',
                                'default_language_code' => 'multi',
                                'languages' => [
                                    ['code' => 'multi', 'label' => 'Multilingual'],
                                    ['code' => 'en', 'label' => 'English'],
                                    ['code' => 'tl', 'label' => 'Tagalog'],
                                ],
                            ],
                        ],
                    ],
                    [
                        'provider' => 'speechmatics',
                        'name' => 'Speechmatics',
                        'configured' => true,
                        'enabled' => true,
                        'connected' => false,
                        'models' => [
                            ['id' => 'melia-1', 'label' => 'Melia-1', 'languages' => []],
                        ],
                    ],
                ],
            ],
        ]);

        $this->assertArrayHasKey('deepgram', $settings->transcriptionProviderOptions());
        $this->assertArrayNotHasKey('speechmatics', $settings->transcriptionProviderOptions());
        $this->assertSame('nova-3', $settings->speechToTextModel('deepgram'));
        $this->assertSame([
            ['value' => 'multi', 'label' => 'Multilingual'],
            ['value' => 'en', 'label' => 'English'],
            ['value' => 'tl', 'label' => 'Tagalog'],
        ], $settings->speechToTextLanguageOptions('deepgram', 'nova-3'));
    }

    public function test_it_falls_back_to_model_default_language_when_selected_language_is_invalid(): void
    {
        $settings = app(AppSettingsService::class);

        $settings->setLicenseStatus([
            'providers' => [
                'transcription' => [
                    [
                        'provider' => 'speechmatics',
                        'name' => 'Speechmatics',
                        'configured' => true,
                        'enabled' => true,
                        'connected' => true,
                        'models' => [
                            [
                                'id' => 'enhanced',
                                'label' => 'Enhanced',
                                'default_language_code' => 'auto',
                                'languages' => [
                                    ['code' => 'auto', 'label' => 'Auto Detect'],
                                    ['code' => 'en', 'label' => 'English'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $settings->setSpeechToTextProvider('speechmatics');
        $settings->setSpeechToTextModel('enhanced');

        $this->assertSame([
            'provider' => 'speechmatics',
            'model' => 'enhanced',
            'language' => 'auto',
        ], $settings->transcriptionSelection('not-returned-by-server'));
    }
}
