<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wp_connections', function (Blueprint $table) {
            $table->json('vulnerabilities')->nullable()->after('inventory');
            $table->timestamp('vulnerabilities_checked_at')->nullable()->after('vulnerabilities');
        });
    }

    public function down(): void
    {
        Schema::table('wp_connections', function (Blueprint $table) {
            $table->dropColumn(['vulnerabilities', 'vulnerabilities_checked_at']);
        });
    }
};
