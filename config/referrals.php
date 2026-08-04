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
