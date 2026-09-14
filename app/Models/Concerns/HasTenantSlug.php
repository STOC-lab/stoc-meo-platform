<?php

namespace App\Models\Concerns;

use App\Support\Tenancy;
use Illuminate\Support\Str;

/**
 * Gives a tenant-owned model a slug derived from its name.
 *
 * The slug is unique within the organization, not globally. Two organizations
 * may both run a "sakura", and a tenant must not be able to learn that another
 * one exists by failing to take a name.
 *
 * `Str::slug()` drops everything it cannot transliterate, and this product's
 * names are Japanese, so most of them reduce to an empty string. The model's
 * own fallback stands in for those, and the counter below does the rest: the
 * first is `store`, the next `store-2`. It is not pretty, but it is stable,
 * readable in a URL, and it never collides.
 */
trait HasTenantSlug
{
    public static function bootHasTenantSlug(): void
    {
        static::saving(function (self $model): void {
            if ($model->slug === null || $model->slug === '' || $model->isDirty('name')) {
                $model->slug = $model->uniqueSlug((string) $model->name);
            }
        });
    }

    /**
     * What a name with nothing transliterable in it becomes.
     */
    protected function slugFallback(): string
    {
        return Str::snake(class_basename(static::class), '-');
    }

    /**
     * The first slug from this name that no other row of the organization
     * holds. A row being saved does not collide with itself.
     */
    protected function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);

        if ($base === '') {
            $base = $this->slugFallback();
        }

        $slug = $base;
        $suffix = 1;

        while ($this->slugIsTaken($slug)) {
            $suffix++;
            $slug = $base.'-'.$suffix;
        }

        return $slug;
    }

    protected function slugIsTaken(string $slug): bool
    {
        return static::acrossTenants()
            ->withTrashed()
            ->where($this->getTenantColumn(), $this->tenantKey())
            ->where('slug', $slug)
            ->when($this->exists, fn ($query) => $query->whereKeyNot($this->getKey()))
            ->exists();
    }

    /**
     * Which organization the row belongs to, while it is being saved.
     *
     * `saving` runs before `creating`, and it is `creating` that stamps the
     * organization on a new row, so the attribute is still null here on a
     * create. Falling back to the active tenant is the same answer
     * BelongsToTenant is about to write, and without it every new row looks
     * unique against organization null and collides on insert.
     */
    protected function tenantKey(): int|string|null
    {
        return $this->getAttribute($this->getTenantColumn()) ?? app(Tenancy::class)->id();
    }
}
