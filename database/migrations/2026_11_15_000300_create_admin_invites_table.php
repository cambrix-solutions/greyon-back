<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Invite by Google". An org admin invites someone by email and picks
     * their seats up front (`packages` = the same entries People/Team takes:
     * packageId + locationIds/hotelIds). The invitee signs in with Google;
     * the account is created then, with no password. Only a SHA-256 hash of
     * the token is stored, so a database leak cannot be turned into logins.
     */
    public function up(): void
    {
        Schema::create('admin_invites', function (Blueprint $table) {
            $table->id();
            $table->string('email')->index();
            $table->string('name')->nullable();
            $table->string('token_hash', 64)->unique();
            $table->json('packages');
            $table->foreignId('invited_by_admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->foreignId('admin_id')->nullable()->constrained('admins')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_invites');
    }
};
