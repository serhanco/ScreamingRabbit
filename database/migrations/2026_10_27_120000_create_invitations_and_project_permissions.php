<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Members join projects by e-mail invitation, each with their own permissions on that project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_user', function (Blueprint $table) {
            // What the member may change on this project (Project::PERMISSIONS); null keeps the old role-based rights.
            $table->json('permissions')->nullable();
        });

        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('email');
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->json('permissions');
            // Only a hash is kept; the link in the e-mail carries the token itself.
            $table->string('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
        Schema::table('project_user', function (Blueprint $table) {
            $table->dropColumn('permissions');
        });
    }
};
