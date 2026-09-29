<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'dataforseo' => [
        'login' => env('DATAFORSEO_LOGIN'),
        'password' => env('DATAFORSEO_PASSWORD'),
        // Sandbox answers with the same shape at no cost, and is the default
        // until live credentials are in place.
        'sandbox' => env('DATAFORSEO_SANDBOX', true),
        'base_url' => env('DATAFORSEO_BASE_URL', 'https://api.dataforseo.com'),
        'sandbox_url' => env('DATAFORSEO_SANDBOX_URL', 'https://sandbox.dataforseo.com'),
        'location_name' => env('DATAFORSEO_LOCATION_NAME', 'Japan'),
        'language_code' => env('DATAFORSEO_LANGUAGE_CODE', 'ja'),
        'depth' => env('DATAFORSEO_DEPTH', 100),
        // The map zoom a heatmap's grid points are searched at, which
        // decides how much ground each one sees.
        'zoom' => env('DATAFORSEO_ZOOM', 14),
        'timeout' => env('DATAFORSEO_TIMEOUT', 30),
    ],

    // The Business Profile connection. The scope is what the whole GBP module
    // runs on, and access_type=offline is what makes Google hand back a
    // refresh token at all — without it the connection dies within the hour.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),
    ],

    // Instagram publishing goes through Meta's Graph API. Publishing needs an
    // Instagram professional account linked to a Facebook page, and an image
    // at a URL Meta can fetch — it does not accept an upload.
    //
    // Connecting uses Instagram Login: the consent screen is on instagram.com,
    // the code is traded at api.instagram.com, and the short-lived token that
    // comes back is exchanged on graph.instagram.com for one that lasts about
    // sixty days.
    'instagram' => [
        'base_url' => env('INSTAGRAM_BASE_URL', 'https://graph.facebook.com'),
        'version' => env('INSTAGRAM_API_VERSION', 'v21.0'),
        'timeout' => env('INSTAGRAM_TIMEOUT', 60),
        'app_id' => env('INSTAGRAM_APP_ID'),
        'app_secret' => env('INSTAGRAM_APP_SECRET'),
        'redirect' => env('INSTAGRAM_REDIRECT_URI'),
        'scopes' => [
            'instagram_business_basic',
            'instagram_business_manage_messages',
            'instagram_business_manage_comments',
        ],
        'authorize_url' => env('INSTAGRAM_AUTHORIZE_URL', 'https://www.instagram.com/oauth/authorize'),
        'token_url' => env('INSTAGRAM_TOKEN_URL', 'https://api.instagram.com/oauth/access_token'),
        'graph_url' => env('INSTAGRAM_GRAPH_URL', 'https://graph.instagram.com'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
