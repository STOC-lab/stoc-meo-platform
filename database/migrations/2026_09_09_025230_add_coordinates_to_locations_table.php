<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A heatmap is a grid of search points laid out around the store front, so the
 * store front needs to know where it is. The columns are nullable because an
 * existing store front has no coordinates yet and stays usable for rank
 * tracking without them; only the heatmap requires them.
 *
 * DECIMAL(10, 7) holds the full range of both axes to roughly a centimetre,
 * and keeps the value exact rather than leaving it to a float.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->decimal('latitude', 10, 7)->nullable()->after('address');
            $table->decimal('longitude', 10, 7)->nullable()->after('latitude');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['latitude', 'longitude']);
        });
    }
};
