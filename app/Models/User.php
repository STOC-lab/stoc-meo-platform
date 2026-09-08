<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\OrganizationRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Organization, $this, OrganizationUser>
     */
    public function organizations(): BelongsToMany
    {
        return $this->belongsToMany(Organization::class, 'organization_users')
            ->using(OrganizationUser::class)
            ->withPivot(['id', 'role'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<OrganizationUser, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(OrganizationUser::class);
    }

    /**
     * The role this user holds in the given organization, or null when they
     * are not a member of it.
     */
    public function roleIn(Organization|int $organization): ?OrganizationRole
    {
        $organizationId = $organization instanceof Organization
            ? $organization->getKey()
            : $organization;

        $role = $this->memberships()
            ->where('organization_id', $organizationId)
            ->value('role');

        return match (true) {
            $role instanceof OrganizationRole => $role,
            is_string($role) => OrganizationRole::tryFrom($role),
            default => null,
        };
    }

    /**
     * Determine whether the user holds at least the given role in the
     * organization.
     */
    public function hasRoleIn(Organization|int $organization, OrganizationRole $role): bool
    {
        return $this->roleIn($organization)?->atLeast($role) ?? false;
    }

    public function belongsToOrganization(Organization|int $organization): bool
    {
        return $this->roleIn($organization) !== null;
    }
}
