<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Anyone who manages a Business Profile — or Google, suggesting an edit —
     * can change a store front's name, phone number or hours from outside the
     * application. Protection keeps what the application holds as the version
     * that counts and puts Google back to it overnight.
     *
     * `gbp_protected_fields` names the fields being held, `gbp_canonical_data`
     * is the snapshot they are held to, and `gbp_last_verified_at` is when
     * Google was last seen to agree with it.
     */
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->json('gbp_protected_fields')->nullable()->after('gbp_location_id');
            $table->json('gbp_canonical_data')->nullable()->after('gbp_protected_fields');
            $table->timestamp('gbp_last_verified_at')->nullable()->after('gbp_canonical_data');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['gbp_protected_fields', 'gbp_canonical_data', 'gbp_last_verified_at']);
        });
    }
};
