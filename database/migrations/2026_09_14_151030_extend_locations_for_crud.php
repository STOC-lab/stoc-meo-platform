<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Store fronts gain a slug, the Japanese address broken into its parts, the
 * Google Maps identifiers, an active flag and soft deletion.
 *
 * `address` stays as it is. It already holds the whole address as one line and
 * is what the GBP sync writes, so the new parts are additional rather than a
 * replacement: nothing is migrated out of it.
 *
 * Soft deletion is the significant change. Ten tables hang off a location —
 * keywords, ranking results, scores, analyses, reviews, posts, heatmaps —
 * and a hard delete took the lot. A deleted store front now keeps its history,
 * which is also what makes deleting one by accident survivable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('name');
            $table->string('postal_code', 16)->nullable()->after('phone');
            $table->string('prefecture', 32)->nullable()->after('postal_code');
            $table->string('city', 64)->nullable()->after('prefecture');
            $table->string('google_place_id')->nullable()->after('longitude');
            $table->string('google_maps_url', 512)->nullable()->after('google_place_id');
            $table->boolean('is_active')->default(true)->after('google_maps_url');
            $table->softDeletes();
        });

        $this->backfillSlugs();

        Schema::table('locations', function (Blueprint $table): void {
            $table->string('slug')->nullable(false)->change();
            $table->unique(['organization_id', 'slug'], 'locations_organization_id_slug_unique');
            $table->index(['organization_id', 'is_active'], 'locations_organization_id_is_active_index');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table): void {
            $table->dropUnique('locations_organization_id_slug_unique');
            $table->dropIndex('locations_organization_id_is_active_index');
            $table->dropColumn([
                'slug',
                'postal_code',
                'prefecture',
                'city',
                'google_place_id',
                'google_maps_url',
                'is_active',
                'deleted_at',
            ]);
        });
    }

    /**
     * As with brands: a Japanese name slugs to nothing, so the id carries the
     * uniqueness and the slug stays stable.
     */
    protected function backfillSlugs(): void
    {
        foreach (DB::table('locations')->select('id', 'name')->orderBy('id')->cursor() as $location) {
            $slug = Str::slug((string) $location->name);

            DB::table('locations')
                ->where('id', $location->id)
                ->update(['slug' => $slug === '' ? 'store-'.$location->id : $slug.'-'.$location->id]);
        }
    }
};
