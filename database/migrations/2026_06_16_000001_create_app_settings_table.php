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
            'key' => 'speech_to_text.provider',
            'value' => 'elevenlabs',
            'is_encrypted' => true,
        ]);

        AppSetting::query()->create([
            'key' => 'deepgram.model',
            'value' => 'nova-3',
            'is_encrypted' => true,
        ]);

        AppSetting::query()->create([
            'key' => 'gemini.model',
            'value' => 'gemini-3.1-flash-lite',
            'is_encrypted' => true,
        ]);

        AppSetting::query()->create([
            'key' => 'gemini.timeout',
            'value' => '30',
            'is_encrypted' => true,
        ]);

        AppSetting::query()->create([
            'key' => 'gemini.max_retries',
            'value' => '3',
            'is_encrypted' => true,
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('app_settings');
    }
};
