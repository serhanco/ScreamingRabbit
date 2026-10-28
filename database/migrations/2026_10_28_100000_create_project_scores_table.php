<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per finished crawl; kept after old crawls are cleaned up so the health trend reaches back further.
        Schema::create('project_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('crawl_id')->nullable()->unique();
            $table->dateTime('scored_at');
            $table->unsignedTinyInteger('health_score');
            $table->unsignedInteger('pages')->default(0);
            $table->unsignedInteger('high')->default(0);
            $table->unsignedInteger('medium')->default(0);
            $table->unsignedInteger('low')->default(0);
            $table->index(['project_id', 'scored_at']);
        });

        $crawls = DB::table('crawls')->where('status', 'completed')->whereNotNull('health_score')->orderBy('id')->get();
        foreach ($crawls as $crawl) {
            $severities = DB::table('crawl_issues')->where('crawl_id', $crawl->id)->groupBy('severity')->selectRaw('severity, COUNT(*) as n')->pluck('n', 'severity');
            DB::table('project_scores')->insert([
                'project_id' => $crawl->project_id,
                'crawl_id' => $crawl->id,
                'scored_at' => $crawl->finished_at ?? $crawl->updated_at ?? $crawl->created_at,
                'health_score' => $crawl->health_score,
                'pages' => DB::table('crawl_pages')->where('crawl_id', $crawl->id)->where('state', 'done')->count(),
                'high' => (int) ($severities['high'] ?? 0),
                'medium' => (int) ($severities['medium'] ?? 0),
                'low' => (int) ($severities['low'] ?? 0),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('project_scores');
    }
};
