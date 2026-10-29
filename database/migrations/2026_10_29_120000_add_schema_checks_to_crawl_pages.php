<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            // Structured data entities missing Google rich result properties, and types printed more than once.
            $table->json('schema_problems')->nullable();
            $table->string('schema_duplicates')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            $table->dropColumn(['schema_problems', 'schema_duplicates']);
        });
    }
};
