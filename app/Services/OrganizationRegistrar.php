<?php

namespace App\Services;

use App\Enums\OrganizationRole;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates the user, their organization and the owner membership that ties the
 * two together, as one unit.
 */
class OrganizationRegistrar
{
    /**
     * The plan a new organization starts on.
     */
    public const DEFAULT_PLAN_CODE = 'meo_free';

    public function register(string $name, string $email, string $password, string $organizationName): User
    {
        return DB::transaction(function () use ($name, $email, $password, $organizationName) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => $password,
            ]);

            $organization = Organization::create([
                'name' => $organizationName,
                'slug' => $this->uniqueSlug($organizationName),
                'status' => Organization::STATUS_ACTIVE,
                'plan_id' => Plan::where('code', self::DEFAULT_PLAN_CODE)->value('id'),
            ]);

            $organization->users()->attach($user, ['role' => OrganizationRole::Owner->value]);

            return $user;
        });
    }

    /**
     * Organization names are free text — Japanese ones can slugify to nothing —
     * so fall back to a generated slug and always suffix on collision.
     */
    protected function uniqueSlug(string $organizationName): string
    {
        $base = Str::slug($organizationName);

        if ($base === '') {
            $base = 'org';
        }

        $slug = $base;

        while (Organization::where('slug', $slug)->exists()) {
            $slug = $base.'-'.Str::lower(Str::random(6));
        }

        return $slug;
    }
}
