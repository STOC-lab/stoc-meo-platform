<?php

namespace App\Models;

use App\Enums\GbpTokenStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One store front's connection to a Google Business Profile.
 *
 * The tokens are cast encrypted, so they are ciphertext at rest and plain
 * strings in code; nothing outside this model should touch the columns
 * directly. They are also hidden, so a connection cannot be serialised into a
 * response by accident.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'google_account_id',
    'access_token_encrypted',
    'refresh_token_encrypted',
    'token_expires_at',
    'token_status',
    'google_email',
    'gbp_account_name',
    'last_synced_at',
])]
class GbpAccount extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $hidden = ['access_token_encrypted', 'refresh_token_encrypted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token_encrypted' => 'encrypted',
            'refresh_token_encrypted' => 'encrypted',
            'token_expires_at' => 'datetime',
            'token_status' => GbpTokenStatus::class,
            'last_synced_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Location, $this>
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /**
     * @param  Builder<GbpAccount>  $query
     */
    public function scopeConnected(Builder $query): void
    {
        $query->where('token_status', GbpTokenStatus::Active);
    }

    public function accessToken(): ?string
    {
        return $this->access_token_encrypted;
    }

    public function refreshToken(): ?string
    {
        return $this->refresh_token_encrypted;
    }

    /**
     * Whether the access token has run out, treating it as spent slightly
     * early so a call does not race the clock.
     */
    public function hasExpiredAccessToken(): bool
    {
        if ($this->token_expires_at === null) {
            return true;
        }

        $skew = (int) config('gbp.token.expiry_skew_seconds', 60);

        return $this->token_expires_at->subSeconds($skew)->isPast();
    }

    /**
     * Whether a call can be attempted: the connection is live and there is
     * something to authenticate with.
     */
    public function isUsable(): bool
    {
        return $this->token_status->isUsable()
            && (filled($this->accessToken()) || filled($this->refreshToken()));
    }

    /**
     * Store a freshly issued access token.
     */
    public function storeAccessToken(string $token, ?int $expiresIn): void
    {
        $this->forceFill([
            'access_token_encrypted' => $token,
            'token_expires_at' => $expiresIn === null ? null : now()->addSeconds($expiresIn),
            'token_status' => GbpTokenStatus::Active,
        ])->save();
    }

    /**
     * Mark the connection as needing a person to reconnect it.
     */
    public function markUnusable(GbpTokenStatus $status): void
    {
        if ($this->token_status === $status) {
            return;
        }

        $this->forceFill(['token_status' => $status])->save();
    }
}
