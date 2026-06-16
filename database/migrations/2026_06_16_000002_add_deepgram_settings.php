<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => 'speech_to_text.provider'],
            ['value' => 'elevenlabs', 'is_encrypted' => true],
        );

        AppSetting::query()->updateOrCreate(
            ['key' => 'deepgram.model'],
            ['value' => 'nova-3', 'is_encrypted' => true],
        );
    }

    public function down(): void
    {
        AppSetting::query()
            ->whereIn('key', [
                'speech_to_text.provider',
                'deepgram.api_key',
                'deepgram.status',
                'deepgram.model',
            ])
            ->delete();
    }
};
