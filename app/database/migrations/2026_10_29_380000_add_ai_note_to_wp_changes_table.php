<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wp_changes', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('status');
            $table->string('note', 500)->nullable()->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('wp_changes', function (Blueprint $table) {
            $table->dropColumn(['source', 'note']);
        });
    }
};
