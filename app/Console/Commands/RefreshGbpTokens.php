<?php

namespace App\Console\Commands;

use App\Models\GbpAccount;
use App\Services\GBP\Exceptions\GBPAuthenticationException;
use App\Services\GBP\GBPTokenRefresher;
use App\Support\Tenancy;
use Illuminate\Console\Command;

/**
 * Renews the access token of every live connection that is inside its refresh
 * window.
 *
 * The window is gbp.token.refresh_lead_days, which design v1.3 sets at sixty
 * days. Google's access tokens last an hour, so in practice every stored token
 * is inside it and this renews them all — which is the behaviour wanted
 * either way. A call renews an expired token itself, so this sweep is what
 * keeps a connection warm between calls rather than the only thing holding it
 * up.
 *
 * A refresh that fails has already marked the connection and raised the alert;
 * this counts it and moves on rather than letting one dead connection stop the
 * rest.
 */
class RefreshGbpTokens extends Command
{
    protected $signature = 'gbp:refresh-tokens';

    protected $description = 'Renew the Google access token of every connection nearing expiry';

    public function handle(GBPTokenRefresher $refresher, Tenancy $tenancy): int
    {
        $threshold = now()->addDays((int) config('gbp.token.refresh_lead_days', 60));
        $refreshed = 0;
        $failed = 0;

        GbpAccount::acrossTenants()
            ->connected()
            ->whereNotNull('refresh_token_encrypted')
            ->where(function ($query) use ($threshold) {
                $query->whereNull('token_expires_at')
                    ->orWhere('token_expires_at', '<=', $threshold);
            })
            ->orderBy('id')
            ->chunkById(100, function ($accounts) use ($refresher, $tenancy, &$refreshed, &$failed) {
                foreach ($accounts as $account) {
                    $tenancy->forOrganization($account->organization_id, function () use ($refresher, $account, &$refreshed, &$failed) {
                        try {
                            $refresher->refresh($account);
                            $refreshed++;
                        } catch (GBPAuthenticationException $e) {
                            $failed++;
                        }
                    });
                }
            });

        $this->info("Refreshed {$refreshed} token(s), {$failed} need reconnecting.");

        return self::SUCCESS;
    }
}
