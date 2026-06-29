<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clean_transcript_chunks', function (Blueprint $table) {
            $table->string('provider', 80)->nullable()->after('clean_timestamps');
            $table->index('provider');
        });
    }

    public function down(): void
    {
        Schema::table('clean_transcript_chunks', function (Blueprint $table) {
            $table->dropIndex(['provider']);
            $table->dropColumn('provider');
        });
    }
};
