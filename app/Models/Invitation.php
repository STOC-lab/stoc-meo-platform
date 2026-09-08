<?php

namespace App\Models;

use App\Enums\OrganizationRole;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An outstanding invitation to join an organization.
 *
 * The token is stored hashed, so a leaked database row cannot be used to
 * accept an invitation; the plaintext exists only in the emailed link.
 */
#[Fillable(['organization_id', 'invited_by', 'email', 'role', 'token', 'expires_at', 'accepted_at'])]
class Invitation extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * How long an invitation stays valid.
     */
    public const LIFETIME_DAYS = 14;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => OrganizationRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Generate a plaintext token for a link, and its stored hash.
     *
     * @return array{0: string, 1: string}
     */
    public static function generateToken(): array
    {
        $plain = Str::random(48);

        return [$plain, static::hashToken($plain)];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /**
     * Look up an invitation by the token from a link, across organizations —
     * the recipient has no tenant context yet.
     *
     * @return Builder<Invitation>
     */
    public static function forToken(string $plain): Builder
    {
        return static::acrossTenants()->where('token', static::hashToken($plain));
    }

    public function isAccepted(): bool
    {
        return $this->accepted_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return ! $this->isAccepted() && ! $this->isExpired();
    }
}
