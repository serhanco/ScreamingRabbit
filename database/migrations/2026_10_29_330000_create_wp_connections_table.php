<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The link between a project and the ScreamingRabbit Connector plugin on its WordPress site (docs/07 §3).
        Schema::create('wp_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            // One-time pairing code (SHA-256 only) and when it stops working.
            $table->string('code_hash', 64)->nullable()->index();
            $table->timestamp('code_expires_at')->nullable();
            // Shared secret for signing requests, encrypted with APP_KEY.
            $table->text('secret')->nullable();
            $table->string('site_url', 2048)->nullable();
            $table->string('plugin_version', 20)->nullable();
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('inventory')->nullable();
            $table->string('sync_error', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wp_connections');
    }
};
