<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            // Links search engines can't follow, links without readable text, and "click here" style link texts.
            $table->unsignedSmallInteger('uncrawlable_links')->default(0);
            $table->unsignedSmallInteger('empty_anchors')->default(0);
            $table->unsignedSmallInteger('generic_anchors')->default(0);
            $table->string('generic_anchor_texts')->nullable();
            // Missing or weak browser security headers, checked on the home page only.
            $table->string('security_headers')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            $table->dropColumn(['uncrawlable_links', 'empty_anchors', 'generic_anchors', 'generic_anchor_texts', 'security_headers']);
        });
    }
};
