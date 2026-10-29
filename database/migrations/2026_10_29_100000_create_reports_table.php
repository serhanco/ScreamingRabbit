<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Monthly client reports (docs/09 Faz 4): a frozen copy of the report, so it stays readable after old crawls
        // are pruned. draft → approved (gets a link, appears in the archive) → sent (e-mailed to the client).
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('crawl_id')->nullable()->constrained()->nullOnDelete();
            $table->string('period', 7);
            $table->string('status', 16)->default('draft');
            $table->unsignedTinyInteger('health_score')->nullable();
            $table->longText('html');
            $table->longText('pdf_html');
            $table->string('token', 64)->nullable()->unique();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('sent_to')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'period']);
        });

        Schema::table('projects', function (Blueprint $table) {
            // Link to the client's archive page listing every approved report of the project.
            $table->string('archive_token', 64)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('archive_token');
        });
        Schema::dropIfExists('reports');
    }
};
