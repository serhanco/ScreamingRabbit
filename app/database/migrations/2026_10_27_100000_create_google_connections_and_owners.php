<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each member connects their own Google account (one connection per user), and projects/clients get an owner so a
 * second admin only sees what they own or are assigned to. The primary admin (Serhan) still sees everything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false);
        });

        Schema::create('google_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('email')->nullable();
            $table->text('refresh_token');
            // Calls made with this connection today (Search Analytics + URL Inspection), shown on the Google page.
            $table->date('calls_on')->nullable();
            $table->unsignedInteger('calls')->default(0);
            $table->unsignedInteger('inspections')->default(0);
            $table->timestamps();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            // The member whose Google connection reads this project's Search Console property.
            $table->foreignId('gsc_user_id')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('clients', function (Blueprint $table) {
            // No foreign key on purpose: adding one rebuilds the table on SQLite, which would cascade-delete projects.
            // A deleted owner only means the client falls back to being visible through its projects.
            $table->foreignId('owner_id')->nullable()->index();
        });

        $setting = fn (string $key) => DB::table('settings')->where('key', $key)->value('value');
        $admins = DB::table('users')->where('role', 'admin')->orderBy('id')->pluck('id');
        $googleOwner = $setting('google_owner_id');
        $primary = $admins->contains((int) $googleOwner) ? (int) $googleOwner : $admins->first();
        if ($primary === null) {
            return;
        }

        DB::table('users')->where('id', $primary)->update(['is_primary' => true]);
        DB::table('projects')->update(['owner_id' => $primary]);
        DB::table('clients')->update(['owner_id' => $primary]);

        // The single global connection from before becomes the connection of the user who made it.
        $token = $setting('google_refresh_token');
        if (filled($token) && rescue(fn () => Crypt::decryptString($token), null, false) !== null) {
            $owner = DB::table('users')->where('id', (int) $googleOwner)->exists() ? (int) $googleOwner : $primary;
            DB::table('google_connections')->insert([
                'user_id' => $owner,
                'email' => $setting('google_email'),
                'refresh_token' => $token, // already encrypted with the app key, same format as the model's cast
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('projects')->whereNotNull('gsc_property')->update(['gsc_user_id' => $owner]);
        }
        DB::table('settings')->whereIn('key', ['google_refresh_token', 'google_email', 'google_owner_id'])->delete();
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['owner_id']);
            $table->dropColumn('owner_id');
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
            $table->dropConstrainedForeignId('gsc_user_id');
        });
        Schema::dropIfExists('google_connections');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_primary');
        });
    }
};
