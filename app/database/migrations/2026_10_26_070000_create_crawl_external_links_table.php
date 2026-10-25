<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawl_external_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 40);
            // queued → done
            $table->string('state')->default('queued');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('error')->nullable();
            $table->unique(['crawl_id', 'url_hash']);
            $table->index(['crawl_id', 'state']);
        });

        Schema::create('crawl_external_refs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_id')->constrained()->cascadeOnDelete();
            $table->foreignId('link_id')->constrained('crawl_external_links')->cascadeOnDelete();
            $table->foreignId('page_id')->constrained('crawl_pages')->cascadeOnDelete();
            $table->index(['crawl_id', 'link_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_external_refs');
        Schema::dropIfExists('crawl_external_links');
    }
};
