<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Critical pages (docs/09 Faz 4, Lumar Protect idea): a few important URLs checked hourly or daily.
        Schema::create('watched_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('url', 500);
            $table->string('frequency', 8)->default('hourly');
            $table->boolean('is_active')->default(true);
            // status, title, noindex, canonical, location of the last check
            $table->json('state')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_changed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('watched_page_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watched_page_id')->constrained()->cascadeOnDelete();
            $table->timestamp('detected_at');
            $table->string('field', 16);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->index(['watched_page_id', 'detected_at']);
        });

        // People who already picked their alert kinds get the new one too; null already means "all".
        foreach (DB::table('users')->whereNotNull('alert_kinds')->get(['id', 'alert_kinds']) as $user) {
            $kinds = json_decode($user->alert_kinds, true) ?: [];
            if (! in_array('page_change', $kinds, true)) {
                DB::table('users')->where('id', $user->id)->update(['alert_kinds' => json_encode([...$kinds, 'page_change'])]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('watched_page_changes');
        Schema::dropIfExists('watched_pages');
    }
};
