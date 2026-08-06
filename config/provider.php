<?php

use App\Domain\Provider\Drivers\AioIptvVerificationDriver;
use App\Domain\Provider\Drivers\FakeVerificationDriver;
use App\Domain\Provider\Drivers\XtreamVerificationDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Active verification driver
    |--------------------------------------------------------------------------
    | Which driver key resolves the CustomerVerificationContract binding.
    | Production, staging and preview environments MUST use "xtream" (or
    | another real driver). The "fake" driver is deliberately absent from
    | the drivers[] map below so it cannot be selected from config, .env,
    | or the Filament admin panel — it exists only for automated tests,
    | which inject it explicitly via $this->app->instance(...).
    */
    'driver' => env('PROVIDER_VERIFICATION_DRIVER', 'xtream'),

    'drivers' => [
        // Standard Xtream Codes upstream. DNS URL, timeout and active-status
        // whitelist are all pulled from the Settings table at runtime by
        // DomainServiceProvider — do NOT put them in .env.
        'xtream' => [
            'class' => XtreamVerificationDriver::class,
        ],

        'aio_iptv_v1' => [
            'class' => AioIptvVerificationDriver::class,
            'url' => env('PROVIDER_VERIFICATION_URL'),
            'api_key' => env('PROVIDER_VERIFICATION_KEY'),
            'timeout' => (int) env('PROVIDER_VERIFICATION_TIMEOUT', 8),
        ],
    ],
];
