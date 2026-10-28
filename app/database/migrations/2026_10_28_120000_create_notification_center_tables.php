<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notification center (docs/08 §3, docs/09 Faz 4): every alert in one list, and what was sent to whom.
        Schema::create('alert_events', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 32);
            // critical | high | medium | low
            $table->string('severity', 16);
            $table->foreignId('project_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->string('dedupe_key', 64)->index();
            $table->timestamp('created_at')->index();
        });

        Schema::create('alert_deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('alert_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // sent | held (quiet hours) | digest (weekly e-mail only) | suppressed (repeat)
            $table->string('status', 16);
            $table->json('channels')->nullable();
            $table->timestamp('created_at');
            $table->timestamp('sent_at')->nullable();
            $table->index(['user_id', 'status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('quiet_from')->nullable();
            $table->unsignedTinyInteger('quiet_to')->nullable();
            $table->boolean('weekly_digest')->default(true);
        });

        // People who already picked their alert kinds get the new ones too; null already means "all".
        foreach (DB::table('users')->whereNotNull('alert_kinds')->get(['id', 'alert_kinds']) as $user) {
            $kinds = json_decode($user->alert_kinds, true) ?: [];
            $kinds = array_values(array_unique([...$kinds, 'new_issue', 'gsc_drop', 'crawl_failed']));
            DB::table('users')->where('id', $user->id)->update(['alert_kinds' => json_encode($kinds)]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['quiet_from', 'quiet_to', 'weekly_digest']);
        });
        Schema::dropIfExists('alert_deliveries');
        Schema::dropIfExists('alert_events');
    }
};
