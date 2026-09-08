<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Moves billing from the user to the organization. Cashier reads the customer
 * id from a `stripe_id` column and keys subscriptions by the customer model's
 * foreign key, so organizations take on Cashier's column names and the users
 * table gives up the customer columns it never used.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['stripe_customer_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->renameColumn('stripe_customer_id', 'stripe_id');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('plan_id');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->index('stripe_id');
            $table->string('pm_type')->nullable()->after('stripe_id');
            $table->string('pm_last_four', 4)->nullable()->after('pm_type');
            $table->timestamp('trial_ends_at')->nullable();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex('subscriptions_user_id_stripe_status_index');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->renameColumn('user_id', 'organization_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['organization_id', 'stripe_status']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['stripe_id', 'pm_type', 'pm_last_four', 'trial_ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('stripe_id')->nullable()->index();
            $table->string('pm_type')->nullable();
            $table->string('pm_last_four', 4)->nullable();
            $table->timestamp('trial_ends_at')->nullable();
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'stripe_status']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->renameColumn('organization_id', 'user_id');
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->index(['user_id', 'stripe_status']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropForeign(['plan_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['stripe_id']);
            $table->dropColumn(['pm_type', 'pm_last_four', 'trial_ends_at', 'plan_id']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->renameColumn('stripe_id', 'stripe_customer_id');
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('plan_id')->nullable();
            $table->index('stripe_customer_id');
        });
    }
};
