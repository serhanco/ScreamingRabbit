<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            // The article author's own page, and whether a page describes a person with Person/ProfilePage schema.
            $table->string('author_url', 2048)->nullable();
            $table->boolean('person_schema')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            $table->dropColumn(['author_url', 'person_schema']);
        });
    }
};
