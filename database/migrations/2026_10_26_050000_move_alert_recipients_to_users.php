<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('alert_email')->nullable();
            $table->string('telegram_chat_id')->nullable();
            $table->json('alert_kinds')->nullable();
        });

        // Until now there was one recipient for the whole app; it belongs to the first (owner) account.
        $global = DB::table('settings')->whereIn('key', ['alert_email', 'telegram_chat_id'])->pluck('value', 'key');
        $owner = DB::table('users')->orderBy('id')->value('id');
        if ($owner && $global->isNotEmpty()) {
            DB::table('users')->where('id', $owner)->update($global->all());
        }
        DB::table('settings')->whereIn('key', ['alert_email', 'telegram_chat_id'])->delete();
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['alert_email', 'telegram_chat_id', 'alert_kinds']);
        });
    }
};
