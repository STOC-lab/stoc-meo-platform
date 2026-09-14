<?php

namespace App\Jobs\Concerns;

use App\Services\GBP\Exceptions\GBPException;
use Illuminate\Support\Facades\Log;

/**
 * What the nightly Business Profile sweeps do with a call Google refused.
 *
 * A transient refusal — a 429 off Google's per-minute meter, a 5xx, a
 * connection that never opened — is retried on the job's backoff, and once the
 * attempts are spent it is written to the log and the job ends. It does not go
 * to `failed_jobs`: the sweeps ask for a window rather than for a single day
 * and overwrite what they already hold, so tonight's rate limit is repaired by
 * tomorrow's run with nothing for anyone to do. A row in `failed_jobs` that
 * nobody needs to act on is the reason the ones that do go unread.
 *
 * A permanent refusal is the opposite and is re-raised every time. It ends the
 * night in `failed_jobs` on purpose, because it stays broken until somebody
 * changes something — the 403 that says the Google My Business API is not
 * enabled on the project will be there again tomorrow.
 */
trait RetriesTransientGbpFailures
{
    /**
     * Whether a transient failure still has an attempt to spend.
     */
    protected function canStillRetry(): bool
    {
        return $this->attempts() < $this->tries;
    }

    /**
     * Decide what a refused call means for this attempt.
     *
     * Returns for a transient failure that has run out of attempts; re-raises
     * in every other case, so the queue either retries the job or records it
     * as failed.
     *
     * @throws GBPException
     */
    protected function handleGbpFailure(GBPException $e): void
    {
        $retrying = $e->isTransient() && $this->canStillRetry();

        Log::warning('Business Profile sync failed.', [
            'job' => static::class,
            'location_id' => $this->location->getKey(),
            'reason' => $e->getMessage(),
            'transient' => $e->isTransient(),
            'attempt' => $this->attempts(),
            'action' => match (true) {
                $retrying => 'retrying on the backoff',
                $e->isTransient() => 'giving up until the next sweep',
                default => 'failing the job',
            },
        ]);

        if ($retrying || ! $e->isTransient()) {
            throw $e;
        }
    }
}
