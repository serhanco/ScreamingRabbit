<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawls', function (Blueprint $table) {
            // Mobile PageSpeed Insights results for a few sample pages: [{url, state, score, lab, field, error}].
            $table->json('pagespeed')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawls', function (Blueprint $table) {
            $table->dropColumn('pagespeed');
        });
    }
};
