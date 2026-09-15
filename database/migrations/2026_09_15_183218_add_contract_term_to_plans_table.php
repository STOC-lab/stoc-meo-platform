<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A plan's price is the amount of one charge, and `interval` says how often
     * that charge falls. Neither says how long the customer is committed for,
     * and with the annual catalogue the two stopped being the same thing: a
     * three-year plan is billed yearly and committed for thirty-six months.
     *
     * `billing_period_months` is the commitment, `phases` is how many charges
     * make it up — the number of Subscription Schedule phases the contract
     * needs. The existing monthly rows are one month and one phase, which is
     * what the defaults give them.
     */
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedSmallInteger('billing_period_months')->default(1)->after('interval');
            $table->unsignedTinyInteger('phases')->default(1)->after('billing_period_months');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['billing_period_months', 'phases']);
        });
    }
};
