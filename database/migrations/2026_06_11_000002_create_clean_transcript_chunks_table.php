<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clean_transcript_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('audio_chunk_id')->constrained('audio_chunks')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id')->default(1);
            $table->string('category_name', 120)->nullable();
            $table->unsignedInteger('clip_index');
            $table->unsignedInteger('clip_start_ms');
            $table->unsignedInteger('clip_end_ms');
            $table->string('range_label', 32);
            $table->longText('raw_text')->nullable();
            $table->longText('clean_text')->nullable();
            $table->json('clean_timestamps')->nullable();
            $table->string('model', 120)->nullable();
            $table->string('status', 32)->default('cleaned');
            $table->timestamps();

            $table->unique('audio_chunk_id');
            $table->index(['user_id', 'category_name']);
            $table->index(['category_name', 'clip_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clean_transcript_chunks');
    }
};
