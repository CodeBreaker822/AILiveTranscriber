<?php

use App\Models\AppSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('app_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->longText('value')->nullable();
            $table->boolean('is_encrypted')->default(false);
            $table->timestamps();
        });

        AppSetting::query()->create([
            'key' => 'transcription_api.base_url',
            'value' => config('services.transcription_api.base_url', 'https://dilgaims.site/api'),
            'is_encrypted' => true,
        ]);

        AppSetting::query()->create([
            'key' => 'transcription_api.license_key',
            'value' => '',
            'is_encrypted' => true,
        ]);

        AppSetting::query()->create([
            'key' => 'speech_to_text.provider',
            'value' => '',
            'is_encrypted' => true,
        ]);

        AppSetting::query()->create([
            'key' => 'speech_to_text.model',
            'value' => '',
            'is_encrypted' => true,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
