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
        if (! Schema::hasTable('audio_chunks') || Schema::hasColumn('audio_chunks', 'category_name')) {
            return;
        }

        Schema::table('audio_chunks', function (Blueprint $table) {
            $table->string('category_name', 120)->nullable()->after('user_id');
            $table->index(['category_name', 'clip_index']);
            $table->index(['user_id', 'category_name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('audio_chunks') || ! Schema::hasColumn('audio_chunks', 'category_name')) {
            return;
        }

        Schema::table('audio_chunks', function (Blueprint $table) {
            $table->dropIndex(['category_name', 'clip_index']);
            $table->dropIndex(['user_id', 'category_name']);
            $table->dropColumn('category_name');
        });
    }
};
