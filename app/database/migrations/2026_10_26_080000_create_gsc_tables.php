<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('gsc_property')->nullable();
            $table->timestamp('gsc_synced_at')->nullable();
        });

        Schema::create('gsc_page_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->text('page');
            $table->char('page_hash', 40);
            $table->unsignedInteger('clicks');
            $table->unsignedInteger('impressions');
            $table->decimal('position', 8, 2);
            $table->unique(['project_id', 'day', 'page_hash']);
            $table->index(['project_id', 'page_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gsc_page_days');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['gsc_property', 'gsc_synced_at']);
        });
    }
};
