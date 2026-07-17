<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AppUpdateControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_app_page_contains_the_shared_update_checker(): void
    {
        foreach (['/', '/upload', '/settings'] as $path) {
            $response = $this->get($path);

            $response
                ->assertOk()
                ->assertSee('data-app-update-dialog', false)
                ->assertSee(route('app-update.connectivity'), false)
                ->assertDontSee('/app-update/status', false)
                ->assertDontSee('/app-update/download', false)
                ->assertSee('js/modals/app-update.js', false);
        }
    }

    public function test_connectivity_probe_is_silent_when_server_is_unreachable(): void
    {
        config(['services.transcription_api.base_url' => 'https://dilgaims.site/api']);

        Http::fake(function (): never {
            throw new \Illuminate\Http\Client\ConnectionException('offline');
        });

        $this->getJson('/app-update/connectivity')
            ->assertOk()
            ->assertJsonPath('online', false)
            ->assertJsonStructure([
                'online',
                'offline_available',
                'offline_model_available',
            ])
            ->assertDontSee('internet', false)
            ->assertDontSee('connection', false);
    }
}
