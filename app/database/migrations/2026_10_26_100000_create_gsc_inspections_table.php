<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Latest URL Inspection result per page; Google allows 2,000 inspections per property per day.
        Schema::create('gsc_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('page_hash', 40);
            $table->text('url');
            $table->string('verdict', 32)->nullable();
            $table->string('coverage_state')->nullable();
            $table->string('indexing_state', 64)->nullable();
            $table->text('google_canonical')->nullable();
            $table->text('user_canonical')->nullable();
            $table->timestamp('last_crawl_time')->nullable();
            $table->string('error')->nullable();
            $table->timestamp('inspected_at');
            $table->unique(['project_id', 'page_hash']);
            $table->index(['project_id', 'inspected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_inspections');
    }
};
