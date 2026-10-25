<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawls', function (Blueprint $table) {
            $table->json('wp_checks')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawls', function (Blueprint $table) {
            $table->dropColumn('wp_checks');
        });
    }
};
