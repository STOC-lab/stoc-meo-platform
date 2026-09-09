<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    */

    'default' => env('AI_PROVIDER', 'claude'),

    /*
    |--------------------------------------------------------------------------
    | Claude
    |--------------------------------------------------------------------------
    |
    | Two models are configured. The fast one writes review replies and social
    | captions — short pieces where turnaround and cost matter more than depth.
    | The strong one is there for work that needs more judgement.
    |
    | Model ids are exact and carry no date suffix.
    |
    */

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'models' => [
            'fast' => env('AI_MODEL_FAST', 'claude-haiku-4-5'),
            'strong' => env('AI_MODEL_STRONG', 'claude-sonnet-4-6'),
        ],
        'max_tokens' => env('AI_MAX_TOKENS', 1024),
        'timeout' => env('AI_TIMEOUT', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Review Replies
    |--------------------------------------------------------------------------
    |
    | Google caps a reply at 4096 characters, but a reply that long reads as
    | machine-written; the prompt asks for something far shorter and this is
    | the ceiling the request is given.
    |
    */

    'review_reply' => [
        'model' => env('AI_REVIEW_REPLY_MODEL', 'fast'),
        'max_tokens' => env('AI_REVIEW_REPLY_MAX_TOKENS', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Campaign Content
    |--------------------------------------------------------------------------
    */

    'campaign' => [
        'model' => env('AI_CAMPAIGN_MODEL', 'fast'),
        'max_tokens' => env('AI_CAMPAIGN_MAX_TOKENS', 1500),
        'hashtag_limit' => env('AI_CAMPAIGN_HASHTAG_LIMIT', 12),
    ],

    /*
    |--------------------------------------------------------------------------
    | Improvement Proposals
    |--------------------------------------------------------------------------
    |
    | Advice worth acting on takes more judgement than a caption does, so this
    | is the one place the stronger model is the default.
    |
    */

    'proposals' => [
        'model' => env('AI_PROPOSALS_MODEL', 'strong'),
        'max_tokens' => env('AI_PROPOSALS_MAX_TOKENS', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Analyses
    |--------------------------------------------------------------------------
    */

    'analysis' => [
        'model' => env('AI_ANALYSIS_MODEL', 'fast'),
        'max_tokens' => env('AI_ANALYSIS_MAX_TOKENS', 1500),
    ],

];
