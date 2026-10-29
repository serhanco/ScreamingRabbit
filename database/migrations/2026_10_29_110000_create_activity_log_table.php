<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Who changed what in the panel (docs/09 Faz 4): settings, projects, team, tasks, reports, monitors.
        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 16);
            $table->string('subject_type', 64);
            $table->string('subject_id', 64)->nullable();
            $table->string('label');
            $table->json('changes')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
