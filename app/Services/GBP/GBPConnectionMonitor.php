<?php

namespace App\Services\GBP;

use App\Enums\AlertType;
use App\Enums\GbpTokenStatus;
use App\Models\Alert;
use App\Models\GbpAccount;

/**
 * Records that a Business Profile connection has stopped working, and tells
 * the organization so.
 *
 * A connection that has gone is not something a retry fixes — somebody has to
 * reconnect the account — so the status is written down and an alert is raised
 * for the dashboard. The alert is raised once per break rather than on every
 * call that runs into it: a nightly sweep over fifty store fronts would
 * otherwise bury the dashboard in the same news.
 */
class GBPConnectionMonitor
{
    /**
     * Mark the connection unusable and raise an alert if this is new.
     */
    public function markUnusable(GbpAccount $account, GbpTokenStatus $status): void
    {
        if ($account->token_status === $status) {
            return;
        }

        $wasUsable = $account->token_status->isUsable();

        $account->markUnusable($status);

        if ($wasUsable) {
            $this->raiseAlert($account, $status);
        }
    }

    /**
     * Note that the connection is working again, clearing any alert that is
     * still open about it.
     */
    public function markRestored(GbpAccount $account): void
    {
        Alert::acrossTenants()
            ->where('organization_id', $account->organization_id)
            ->where('location_id', $account->location_id)
            ->where('type', AlertType::GbpTokenExpired)
            ->unread()
            ->update(['is_read' => true]);
    }

    protected function raiseAlert(GbpAccount $account, GbpTokenStatus $status): void
    {
        Alert::create([
            'organization_id' => $account->organization_id,
            'location_id' => $account->location_id,
            'keyword_id' => null,
            'type' => AlertType::GbpTokenExpired,
            'payload' => [
                'token_status' => $status->value,
                'status_label' => $status->label(),
                'google_email' => $account->google_email,
                'message' => 'Googleビジネスプロフィールとの連携が切れました。再接続してください。',
            ],
            'is_read' => false,
        ]);
    }
}
