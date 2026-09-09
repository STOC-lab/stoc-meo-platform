<?php

return [

    /*
    |--------------------------------------------------------------------------
    | OAuth Scopes
    |--------------------------------------------------------------------------
    |
    | One scope covers the whole Business Profile family: Google does not split
    | it per API. The two OpenID scopes are what identify the Google account
    | the connection was made with.
    |
    */

    'scopes' => [
        'https://www.googleapis.com/auth/business.manage',
        'openid',
        'email',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tokens
    |--------------------------------------------------------------------------
    |
    | refresh_lead_days is how far ahead of expiry the scheduled sweep renews a
    | token. Google's access tokens last an hour, so in practice every stored
    | token is inside this window and the sweep renews them all; the setting is
    | what design v1.3 asks for and what would matter if Google ever issued a
    | longer-lived token.
    |
    | A call also renews a token it finds expired, so a connection works
    | between sweeps.
    |
    */

    'token' => [
        'refresh_lead_days' => env('GBP_TOKEN_REFRESH_LEAD_DAYS', 60),
        // Renewing exactly at expiry races the clock, so a token is treated as
        // spent this many seconds early.
        'expiry_skew_seconds' => env('GBP_TOKEN_EXPIRY_SKEW', 60),
        'endpoint' => env('GBP_TOKEN_ENDPOINT', 'https://oauth2.googleapis.com/token'),
    ],

    /*
    |--------------------------------------------------------------------------
    | HTTP
    |--------------------------------------------------------------------------
    */

    'timeout' => env('GBP_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Performance Sync
    |--------------------------------------------------------------------------
    |
    | Google's performance API only serves whole days that have closed, and
    | keeps roughly eighteen months. The weekly sweep asks for the days behind
    | it, with a lag so a day is not asked for before Google has finished it.
    |
    */

    'performance' => [
        'lookback_days' => env('GBP_PERFORMANCE_LOOKBACK_DAYS', 7),
        'lag_days' => env('GBP_PERFORMANCE_LAG_DAYS', 2),
        'metrics' => [
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
            'BUSINESS_DIRECTION_REQUESTS',
            'CALL_CLICKS',
            'WEBSITE_CLICKS',
            'BUSINESS_CONVERSATIONS',
        ],
    ],

];
