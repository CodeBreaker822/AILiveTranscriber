<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcript_summaries', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->default(1);
            $table->string('category_name', 120);
            $table->string('source_type', 16)->default('raw');
            $table->longText('summary_text')->nullable();
            $table->string('provider', 80)->nullable();
            $table->string('model', 120)->nullable();
            $table->string('status', 24)->default('processing');
            $table->text('error_message')->nullable();
            $table->uuid('run_token');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'category_name']);
            $table->index(['category_name', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transcript_summaries');
    }
};
