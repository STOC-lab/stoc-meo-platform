<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One store front's connection to a Google Business Profile.
 *
 * The tokens are held encrypted by the application rather than by the
 * database, so the columns are text: ciphertext is far longer than the token
 * inside it, and its length moves with the key and the payload.
 *
 * A store front connects once, so the row is unique per location; reconnecting
 * replaces the tokens on the row it already has.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gbp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            // The Google account the connection was made with, kept so a
            // reconnection under a different account is visible as one.
            $table->string('google_account_id');
            $table->text('access_token_encrypted')->nullable();
            $table->text('refresh_token_encrypted')->nullable();
            $table->dateTime('token_expires_at')->nullable();
            $table->enum('token_status', ['active', 'expired', 'revoked'])->default('active');
            // What the connection is called on Google's side, for the UI to
            // show which profile a store front is pointed at.
            $table->string('google_email')->nullable();
            $table->string('gbp_account_name')->nullable();
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamps();

            $table->unique('location_id');
            // The refresh sweep looks for live connections nearing expiry.
            $table->index(['token_status', 'token_expires_at']);
            $table->index(['organization_id', 'token_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gbp_accounts');
    }
};
