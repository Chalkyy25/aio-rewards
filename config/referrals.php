<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Attribution cookie
    |--------------------------------------------------------------------------
    | First-touch attribution: the first valid referral link wins for the
    | configured window; subsequent clicks record ReferralClick rows but do
    | not overwrite the cookie.
    */
    'cookie' => [
        'name' => env('REFERRAL_COOKIE_NAME', 'aior_ref'),
        'days' => (int) env('REFERRAL_COOKIE_DAYS', 30),
    ],

    'code' => [
        'length' => 8,
        'alphabet' => 'crockford_base32',
    ],

    'attribution' => [
        'strategy' => 'first_touch',
    ],

    /*
    |--------------------------------------------------------------------------
    | Conversion approval window
    |--------------------------------------------------------------------------
    | A paid purchase from a referred visitor creates a `pending`
    | ReferralConversion. After this many days without a refund/chargeback,
    | the conversion becomes eligible for approval by an admin (or an
    | automatic sweeper job in a later phase).
    */
    'conversion' => [
        // Days a paid, referred order must sit fulfilled before a
        // ReferralConversion becomes eligible for automatic approval.
        // Change via .env: REFERRAL_APPROVAL_WINDOW_DAYS=14
        'approval_window_days' => (int) env('REFERRAL_APPROVAL_WINDOW_DAYS', env('REFERRAL_REFUND_WINDOW_DAYS', 14)),
        // Alias retained for existing pending_until stamping.
        'refund_window_days' => (int) env('REFERRAL_REFUND_WINDOW_DAYS', env('REFERRAL_APPROVAL_WINDOW_DAYS', 14)),
        // How many conversions to lock and process per batch when the
        // scheduled sweeper runs.
        'approval_batch_size' => (int) env('REFERRAL_APPROVAL_BATCH_SIZE', 100),
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limits (per minute)
    |--------------------------------------------------------------------------
    */
    'click_rate_limits' => [
        'per_ip_per_min' => (int) env('REFERRAL_RATE_IP', 60),
        'per_code_per_min' => (int) env('REFERRAL_RATE_CODE', 600),
    ],

    /*
    |--------------------------------------------------------------------------
    | Bot detection
    |--------------------------------------------------------------------------
    | Basic substring match against user-agent. Case-insensitive.
    */
    'bot_ua_substrings' => [
        'bot', 'crawler', 'spider', 'scraper', 'fetcher',
        'wget', 'curl/', 'python-requests', 'httpclient',
        'headlesschrome', 'phantomjs', 'slurp', 'ahrefsbot',
        'semrush', 'facebookexternalhit', 'pingdom', 'monitor',
        'uptimerobot',
    ],

    'default_redirect_after_click' => '/',
];
