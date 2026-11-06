<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawls', function (Blueprint $table) {
            // Site-wide speed scan: every crawled page is measured with PageSpeed Insights after the crawl.
            $table->boolean('speed_scan')->default(false)->index();
        });

        Schema::create('speed_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 40);
            $table->string('page_type', 30)->nullable();
            $table->string('strategy', 10);
            // pending → running → done | failed
            $table->string('state', 10)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('score')->nullable();
            // Lighthouse lab values (ms, CLS unitless).
            $table->unsignedInteger('fcp')->nullable();
            $table->unsignedInteger('lcp')->nullable();
            $table->unsignedInteger('tbt')->nullable();
            $table->decimal('cls', 6, 3)->nullable();
            $table->unsignedInteger('si')->nullable();
            $table->unsignedInteger('ttfb')->nullable();
            // Real Chrome users (CrUX, 75th percentile over 28 days); null when the page has too little traffic.
            $table->unsignedInteger('field_lcp')->nullable();
            $table->unsignedInteger('field_inp')->nullable();
            $table->decimal('field_cls', 5, 2)->nullable();
            $table->unsignedInteger('field_fcp')->nullable();
            $table->unsignedInteger('field_ttfb')->nullable();
            // good | needs | poor: the Core Web Vitals assessment (field data, else lab LCP/CLS + TBT).
            $table->string('cwv', 10)->nullable();
            $table->boolean('cwv_from_field')->default(false);
            $table->string('error', 300)->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();

            $table->unique(['crawl_id', 'url_hash', 'strategy']);
            $table->index(['state', 'crawl_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('speed_results');
        Schema::table('crawls', function (Blueprint $table) {
            $table->dropIndex(['speed_scan']);
            $table->dropColumn('speed_scan');
        });
    }
};
