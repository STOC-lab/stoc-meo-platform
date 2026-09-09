<?php

namespace App\Models;

use App\Enums\InstagramTokenStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One store front's connection to an Instagram professional account.
 *
 * The token is cast encrypted and hidden, so it is ciphertext at rest and
 * cannot be serialised into a response by accident.
 */
#[Fillable([
    'organization_id',
    'location_id',
    'ig_user_id',
    'username',
    'access_token_encrypted',
    'token_expires_at',
    'token_status',
    'last_published_at',
])]
class InstagramAccount extends Model
{
    use BelongsToTenant, HasFactory;

    /**
     * @var list<string>
     */
    protected $hidden = ['access_token_encrypted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'access_token_encrypted' => 'encrypted',
            'token_expires_at' => 'datetime',
            'token_status' => InstagramTokenStatus::class,
            'last_published_at' => 'datetime',
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
     * @param  Builder<InstagramAccount>  $query
     */
    public function scopeConnected(Builder $query): void
    {
        $query->where('token_status', InstagramTokenStatus::Active);
    }

    public function accessToken(): ?string
    {
        return $this->access_token_encrypted;
    }

    /**
     * Whether a call can be attempted: the connection is live, there is a
     * token, and it has not run out.
     */
    public function isUsable(): bool
    {
        return $this->token_status->isUsable()
            && filled($this->accessToken())
            && ($this->token_expires_at === null || $this->token_expires_at->isFuture());
    }

    public function markUnusable(InstagramTokenStatus $status): void
    {
        if ($this->token_status === $status) {
            return;
        }

        $this->forceFill(['token_status' => $status])->save();
    }
}
