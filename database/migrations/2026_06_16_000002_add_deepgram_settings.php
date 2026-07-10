<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        AppSetting::query()->updateOrCreate(
            ['key' => 'speech_to_text.provider'],
            ['value' => '', 'is_encrypted' => true],
        );

        AppSetting::query()->updateOrCreate(
            ['key' => 'speech_to_text.model'],
            ['value' => '', 'is_encrypted' => true],
        );
    }

    public function down(): void
    {
        AppSetting::query()
            ->whereIn('key', [
                'speech_to_text.provider',
                'speech_to_text.model',
            ])
            ->delete();
    }
};
