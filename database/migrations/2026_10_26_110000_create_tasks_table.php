<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            // One task per problem type and project (e.g. "rule:title_missing", "gsc:low_ctr"), updated instead of duplicated.
            $table->string('key');
            $table->string('source', 16);
            $table->string('title');
            $table->text('why');
            $table->string('severity', 16)->nullable();
            $table->unsignedInteger('affected')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unsignedInteger('score')->default(0);
            // open → doing → done → verified (verified is set automatically when the problem is gone)
            $table->string('status', 16)->default('open');
            $table->text('note')->nullable();
            $table->date('due_on')->nullable();
            $table->boolean('show_in_report')->default(false);
            $table->json('baseline')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'key']);
            $table->index(['status', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
