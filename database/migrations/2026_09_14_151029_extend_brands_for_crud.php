<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Brands gain the fields the CRUD API exposes, and a slug of their own.
 *
 * The slug is unique per organization rather than globally: two chains in
 * different organizations may both be "sakura", and one tenant must never be
 * able to tell that the other exists by failing to take a name.
 *
 * Deletion becomes soft. A brand is what a chain's locations are grouped
 * under, and getting one back is otherwise a restore from backup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->string('slug')->nullable()->after('name');
            $table->string('logo_url', 512)->nullable()->after('slug');
            $table->text('description')->nullable()->after('logo_url');
            $table->string('website_url')->nullable()->after('description');
            $table->softDeletes();
        });

        $this->backfillSlugs();

        Schema::table('brands', function (Blueprint $table): void {
            $table->string('slug')->nullable(false)->change();
            $table->unique(['organization_id', 'slug'], 'brands_organization_id_slug_unique');
        });
    }

    public function down(): void
    {
        Schema::table('brands', function (Blueprint $table): void {
            $table->dropUnique('brands_organization_id_slug_unique');
            $table->dropColumn(['slug', 'logo_url', 'description', 'website_url', 'deleted_at']);
        });
    }

    /**
     * Give the rows that are already here a slug.
     *
     * Str::slug() drops every character it cannot transliterate, and this
     * product's brand names are Japanese, so most of them reduce to nothing.
     * A row that slugs to an empty string falls back to its own id, which is
     * unique by construction and stable.
     */
    protected function backfillSlugs(): void
    {
        foreach (DB::table('brands')->select('id', 'organization_id', 'name')->orderBy('id')->cursor() as $brand) {
            $slug = Str::slug((string) $brand->name);

            DB::table('brands')
                ->where('id', $brand->id)
                ->update(['slug' => $slug === '' ? 'brand-'.$brand->id : $slug.'-'.$brand->id]);
        }
    }
};
