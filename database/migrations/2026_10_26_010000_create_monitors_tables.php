<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->unsignedTinyInteger('interval_minutes')->default(1);
            $table->string('keyword')->nullable();
            $table->boolean('is_active')->default(true);
            // unknown → up | down
            $table->string('status')->default('unknown');
            $table->timestamp('last_checked_at')->nullable();
            $table->unsignedInteger('last_response_ms')->nullable();
            $table->unsignedTinyInteger('slow_streak')->default(0);
            $table->timestamp('ssl_expires_at')->nullable();
            $table->timestamp('ssl_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('monitor_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('checked_at');
            $table->boolean('ok');
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->unsignedInteger('response_ms')->nullable();
            // dns | timeout | connect | ssl | http | keyword
            $table->string('error_type', 16)->nullable();
            $table->index(['monitor_id', 'checked_at']);
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('cause', 16);
            $table->string('detail')->nullable();
            $table->timestamp('notified_down_at')->nullable();
            $table->timestamp('notified_up_at')->nullable();
            $table->timestamps();
            $table->index(['monitor_id', 'ended_at']);
        });

        // Every existing project starts with its home page watched; new projects get one on creation.
        $now = now();
        foreach (DB::table('projects')->select('id', 'base_url')->get() as $project) {
            DB::table('monitors')->insert(['project_id' => $project->id, 'url' => $project->base_url, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('monitor_checks');
        Schema::dropIfExists('monitors');
    }
};
