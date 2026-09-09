<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One store front's connection to an Instagram professional account.
 *
 * As with the Business Profile connection, the token is held encrypted by the
 * application rather than by the database, so the column is text: ciphertext
 * is far longer than the token inside it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instagram_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            // The Instagram professional account id publishing is done as.
            $table->string('ig_user_id');
            $table->string('username')->nullable();
            $table->text('access_token_encrypted')->nullable();
            // Meta's long-lived tokens run on a sixty-day clock, so unlike the
            // Google connection this expiry is a date worth watching.
            $table->dateTime('token_expires_at')->nullable();
            $table->enum('token_status', ['active', 'expired', 'revoked'])->default('active');
            $table->dateTime('last_published_at')->nullable();
            $table->timestamps();

            $table->unique('location_id');
            $table->index(['token_status', 'token_expires_at']);
            $table->index(['organization_id', 'token_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('instagram_accounts');
    }
};
