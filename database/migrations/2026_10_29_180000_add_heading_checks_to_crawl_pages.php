<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            // More than one differing canonical (several tags, or tag vs. HTTP Link header).
            $table->boolean('canonical_conflict')->default(false);
            $table->unsignedSmallInteger('h2_count')->nullable();
            // First skipped heading level, e.g. "H2 → H4".
            $table->string('heading_skip', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            $table->dropColumn(['canonical_conflict', 'h2_count', 'heading_skip']);
        });
    }
};
