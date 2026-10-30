<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawls', function (Blueprint $table) {
            // The cookies of a form login, encrypted; only for the crawl's own batches.
            $table->text('session_cookie')->nullable();
            $table->string('login_result', 300)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawls', function (Blueprint $table) {
            $table->dropColumn(['session_cookie', 'login_result']);
        });
    }
};
