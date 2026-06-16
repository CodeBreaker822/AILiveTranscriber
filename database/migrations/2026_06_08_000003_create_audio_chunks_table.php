<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audio_chunks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1);
            $table->string('category_name', 120)->nullable();
            $table->unsignedInteger('clip_index');
            $table->unsignedInteger('clip_start_ms');
            $table->unsignedInteger('clip_end_ms');
            $table->string('range_label', 32);
            $table->unsignedInteger('duration_ms');
            $table->string('mime_type', 120)->nullable();
            $table->string('original_name')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->binary('audio_blob');
            $table->longText('translated_text')->nullable();
            $table->string('status', 32)->default('stored');
            $table->timestamps();

            $table->index(['category_name', 'clip_index']);
            $table->index(['user_id', 'category_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_chunks');
    }
};
