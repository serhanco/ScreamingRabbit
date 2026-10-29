<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            // AI search (GEO/AEO) and E-E-A-T signals.
            $table->unsignedSmallInteger('question_headings')->default(0);
            $table->unsignedSmallInteger('long_paragraphs')->default(0);
            $table->boolean('is_article')->default(false);
            $table->date('published_at')->nullable();
            $table->date('modified_at')->nullable();
            $table->boolean('has_author')->default(false);
            $table->unsignedSmallInteger('same_as')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            $table->dropColumn(['question_headings', 'long_paragraphs', 'is_article', 'published_at', 'modified_at', 'has_author', 'same_as']);
        });
    }
};
