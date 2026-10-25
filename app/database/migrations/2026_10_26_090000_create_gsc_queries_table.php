<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('gsc_queries_synced_on')->nullable();
        });

        // Page × query totals for the latest 28 days, replaced once a day.
        Schema::create('gsc_queries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->char('page_hash', 40);
            $table->text('page');
            $table->string('query', 500);
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->decimal('position', 8, 2);
            $table->index(['project_id', 'page_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_queries');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('gsc_queries_synced_on');
        });
    }
};
