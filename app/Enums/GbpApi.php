<?php

namespace App\Enums;

/**
 * The eight Google APIs a Business Profile is worked through, as listed in
 * STOC MEO SYSTEM DESIGN v1.3 §21.
 *
 * Google split the old Google My Business API into a family of independent
 * services, each on its own host and version — but not completely: reviews,
 * local posts and media were never given a successor, so v4 of the original
 * API is still the only way to reach them and stays in the list.
 */
enum GbpApi: string
{
    /** Reviews, local posts and media. No successor exists; still current. */
    case Legacy = 'legacy';

    case BusinessInformation = 'business_information';
    case AccountManagement = 'account_management';
    case Performance = 'performance';
    case Notifications = 'notifications';
    case Verifications = 'verifications';
    case PlaceActions = 'place_actions';
    case QandA = 'qanda';

    /**
     * The host this API answers on.
     */
    public function host(): string
    {
        return match ($this) {
            self::Legacy => 'https://mybusiness.googleapis.com',
            self::BusinessInformation => 'https://mybusinessbusinessinformation.googleapis.com',
            self::AccountManagement => 'https://mybusinessaccountmanagement.googleapis.com',
            self::Performance => 'https://businessprofileperformance.googleapis.com',
            self::Notifications => 'https://mybusinessnotifications.googleapis.com',
            self::Verifications => 'https://mybusinessverifications.googleapis.com',
            self::PlaceActions => 'https://mybusinessplaceactions.googleapis.com',
            self::QandA => 'https://mybusinessqanda.googleapis.com',
        };
    }

    /**
     * The path segment the version sits at.
     */
    public function version(): string
    {
        return $this === self::Legacy ? 'v4' : 'v1';
    }

    /**
     * The base every request to this API is built on.
     */
    public function baseUrl(): string
    {
        return $this->host().'/'.$this->version();
    }
}
