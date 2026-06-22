<?php

namespace Tests\Feature;

use Tests\TestCase;

class ApiKeyHelpTest extends TestCase
{
    public function test_help_page_only_shows_api_key_acquisition_links(): void
    {
        $response = $this->get('/settings/api-key-help');

        $response
            ->assertOk()
            ->assertSee('Open ElevenLabs key page')
            ->assertSee('Open Deepgram key page')
            ->assertSee('Open Gemini key page')
            ->assertDontSee('Official docs')
            ->assertDontSee('API reference');
    }
}
