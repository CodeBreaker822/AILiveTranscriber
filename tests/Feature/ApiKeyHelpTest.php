<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiKeyHelpTest extends TestCase
{
    public function test_help_page_explains_license_key_setup_only(): void
    {
        $response = $this->get('/settings/api-key-help');

        $response
            ->assertOk()
            ->assertSee('Use your AITranscriber license key')
            ->assertSee('Available providers, models, and languages will load automatically from the server.')
            ->assertDontSee('Open ElevenLabs key page')
            ->assertDontSee('Open Deepgram key page')
            ->assertDontSee('Open Speechmatics key page')
            ->assertDontSee('Open Gemini key page')
            ->assertDontSee('Official docs')
            ->assertDontSee('API reference');
    }
}
