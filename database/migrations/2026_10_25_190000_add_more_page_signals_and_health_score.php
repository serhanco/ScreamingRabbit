<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            $table->unsignedSmallInteger('images_missing_alt')->default(0);
            $table->unsignedSmallInteger('mixed_content')->default(0);
            $table->unsignedSmallInteger('http_internal_links')->default(0);
            $table->string('meta_refresh')->nullable();
            $table->string('page_type', 32)->nullable();
            $table->string('seo_plugin')->nullable();
        });

        Schema::table('crawls', function (Blueprint $table) {
            $table->unsignedTinyInteger('health_score')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            $table->dropColumn(['images_missing_alt', 'mixed_content', 'http_internal_links', 'meta_refresh', 'page_type', 'seo_plugin']);
        });

        Schema::table('crawls', function (Blueprint $table) {
            $table->dropColumn('health_score');
        });
    }
};
