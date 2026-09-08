<?php

namespace App\Support;

use App\Models\Organization;

/**
 * Holds the organization the current request (or job) is acting on. The
 * BelongsToTenant global scope reads from here, so anything that runs outside
 * an HTTP request — queued jobs, console commands — must set it explicitly or
 * the scope stays inactive.
 */
class Tenancy
{
    protected ?Organization $organization = null;

    /**
     * Set the active organization.
     */
    public function set(Organization|int|null $organization): static
    {
        $this->organization = is_int($organization)
            ? Organization::withoutGlobalScopes()->find($organization)
            : $organization;

        return $this;
    }

    public function forget(): static
    {
        $this->organization = null;

        return $this;
    }

    public function check(): bool
    {
        return $this->organization !== null;
    }

    public function organization(): ?Organization
    {
        return $this->organization;
    }

    public function id(): ?int
    {
        return $this->organization?->getKey();
    }

    /**
     * Run the callback with no active organization, then restore the previous
     * one. Useful for cross-tenant admin work and scheduled jobs.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function withoutTenancy(callable $callback): mixed
    {
        $previous = $this->organization;
        $this->organization = null;

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }

    /**
     * Run the callback with the given organization active, then restore the
     * previous one.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function forOrganization(Organization|int $organization, callable $callback): mixed
    {
        $previous = $this->organization;
        $this->set($organization);

        try {
            return $callback();
        } finally {
            $this->organization = $previous;
        }
    }
}
