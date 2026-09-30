<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesTransientGbpFailures;
use App\Models\Location;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\Exceptions\GBPException;
use App\Services\GBP\GBPClientFactory;
use App\Services\GBP\GBPLocationProtection;
use App\Support\Tenancy;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Puts one store front's Business Profile back to the snapshot the
 * application holds, for the fields it has been asked to protect.
 *
 * The application is the version that counts: a name, number or set of hours
 * changed on Google's side — by another manager, or by an edit Google itself
 * accepted — is written back over, and the log says what was found and what
 * it was replaced with.
 *
 * Like the other nightly sweeps it reads the whole of what it checks and
 * writes only the snapshot, so a night lost to Google's meter is made good by
 * the next one; see the trait for what that means for `failed_jobs`.
 */
class SyncGBPLocationJob implements ShouldQueue
{
    use Queueable;
    use RetriesTransientGbpFailures;

    public const QUEUE = 'gbp';

    /**
     * Four attempts rather than three, so the last of the backoff delays is
     * reached.
     */
    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(public Location $location)
    {
        $this->onQueue(self::QUEUE);
    }

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function handle(GBPClientFactory $clients, GBPLocationProtection $protection, Tenancy $tenancy): void
    {
        $tenancy->forOrganization($this->location->organization_id, function () use ($clients, $protection) {
            $canonical = (array) $this->location->gbp_canonical_data;

            if (! $this->location->isGbpProtected() || $canonical === [] || ! $this->location->isLinkedToGbp()) {
                return;
            }

            try {
                $account = $clients->connectionFor($this->location);
            } catch (GBPAuthenticationException $e) {
                return;
            }

            try {
                $current = $protection->current($account, $this->location);
            } catch (GBPException $e) {
                $this->handleGbpFailure($e);

                return;
            }

            $drifted = $protection->drift($current, $canonical, (array) $this->location->gbp_protected_fields);

            if ($drifted !== []) {
                $restored = $protection->pick($canonical, $drifted);

                Log::warning('Business Profile changed outside the application; restoring it.', [
                    'location_id' => $this->location->getKey(),
                    'gbp_location_id' => $this->location->gbp_location_id,
                    'fields' => $drifted,
                    'found' => $protection->pick($current, $drifted),
                    'restoring' => $restored,
                ]);

                try {
                    $clients->businessInfo($account)->updateLocation((string) $this->location->gbp_location_id, $restored);
                } catch (GBPException $e) {
                    $this->handleGbpFailure($e);

                    return;
                }

                Log::warning('Business Profile restored to the protected snapshot.', [
                    'location_id' => $this->location->getKey(),
                    'gbp_location_id' => $this->location->gbp_location_id,
                    'fields' => $drifted,
                ]);
            }

            $this->location->forceFill(['gbp_last_verified_at' => now()])->save();
        });
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return [
            'gbp',
            'location-protection',
            'organization:'.$this->location->organization_id,
            'location:'.$this->location->getKey(),
        ];
    }
}
