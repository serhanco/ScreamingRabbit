<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monitor_daily', function (Blueprint $table) {
            $table->id();
            $table->foreignId('monitor_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->unsignedInteger('checks');
            $table->unsignedInteger('up_checks');
            $table->unsignedInteger('avg_ms')->nullable();
            $table->unique(['monitor_id', 'day']);
        });

        Schema::table('incidents', function (Blueprint $table) {
            $table->timestamp('reminded_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_daily');
        Schema::table('incidents', function (Blueprint $table) {
            $table->dropColumn('reminded_at');
        });
    }
};
