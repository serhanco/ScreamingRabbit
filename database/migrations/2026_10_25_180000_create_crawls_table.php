<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crawls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // pending → running → completed | failed | cancelled
            $table->string('status')->default('pending');
            $table->json('settings')->nullable();
            $table->longText('robots_txt')->nullable();
            $table->boolean('sitemap_found')->default(false);
            $table->string('detected_platform')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('crawl_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_id')->constrained()->cascadeOnDelete();
            $table->text('url');
            $table->char('url_hash', 40);
            // queued → done | blocked
            $table->string('state')->default('queued');
            $table->unsignedSmallInteger('depth')->default(0);
            $table->boolean('in_sitemap')->default(false);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('content_type')->nullable();
            $table->text('redirect_to')->nullable();
            $table->text('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->unsignedSmallInteger('h1_count')->default(0);
            $table->text('h1')->nullable();
            $table->text('canonical')->nullable();
            $table->string('meta_robots')->nullable();
            $table->string('x_robots')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->char('content_hash', 32)->nullable();
            $table->unsignedInteger('size_bytes')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            $table->unsignedInteger('inlinks')->default(0);
            $table->boolean('wordpress_hint')->default(false);
            $table->text('error')->nullable();
            $table->timestamp('fetched_at')->nullable();

            $table->unique(['crawl_id', 'url_hash']);
            $table->index(['crawl_id', 'state', 'depth']);
        });

        Schema::create('crawl_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_page_id')->constrained('crawl_pages')->cascadeOnDelete();
            $table->char('to_hash', 40);
            $table->boolean('nofollow')->default(false);

            $table->index(['crawl_id', 'to_hash']);
        });

        Schema::create('crawl_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('crawl_id')->constrained()->cascadeOnDelete();
            $table->foreignId('page_id')->nullable()->constrained('crawl_pages')->cascadeOnDelete();
            $table->string('rule', 64);
            $table->string('severity', 16);
            $table->string('detail')->nullable();

            $table->index(['crawl_id', 'rule']);
            $table->index(['crawl_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crawl_issues');
        Schema::dropIfExists('crawl_links');
        Schema::dropIfExists('crawl_pages');
        Schema::dropIfExists('crawls');
    }
};
