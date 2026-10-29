<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            // rel="next"/"prev" links of a paginated series and whether a normal link also points there.
            $table->json('pagination')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('crawl_pages', function (Blueprint $table) {
            $table->dropColumn('pagination');
        });
    }
};
