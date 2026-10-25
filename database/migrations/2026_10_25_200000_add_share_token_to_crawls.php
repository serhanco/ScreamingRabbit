<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawls', function (Blueprint $table) {
            // Public report link; null means the report is not shared.
            $table->string('share_token', 64)->nullable()->unique();
            $table->timestamp('shared_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawls', function (Blueprint $table) {
            $table->dropColumn(['share_token', 'shared_at']);
        });
    }
};
