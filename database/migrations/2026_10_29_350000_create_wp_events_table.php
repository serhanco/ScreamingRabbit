<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wp_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('event', 20);
            $table->string('url', 2048);
            $table->string('post_type', 40)->nullable();
            $table->timestamp('received_at');
            $table->timestamp('checked_at')->nullable();
            $table->json('state')->nullable();
            $table->json('problems')->nullable();
            $table->index(['project_id', 'received_at']);
            $table->index('checked_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_events');
    }
};
