<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audio_vad_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1);
            $table->string('category_name', 120)->nullable();
            $table->string('source_type', 24);
            $table->unsignedInteger('clip_index');
            $table->unsignedInteger('clip_start_ms');
            $table->unsignedInteger('clip_end_ms');
            $table->string('range_label', 32);
            $table->unsignedInteger('duration_ms');
            $table->boolean('speech_detected');
            $table->unsignedInteger('speech_duration_ms')->default(0);
            $table->unsignedInteger('segment_count')->default(0);
            $table->json('speech_segments')->nullable();
            $table->string('input_name')->nullable();
            $table->unsignedBigInteger('input_size_bytes')->default(0);
            $table->string('filtered_name')->nullable();
            $table->unsignedBigInteger('filtered_size_bytes')->default(0);
            $table->string('status', 32)->default('checked');
            $table->string('message')->nullable();
            $table->timestamps();

            $table->index(['category_name', 'clip_index']);
            $table->index(['speech_detected', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audio_vad_logs');
    }
};
