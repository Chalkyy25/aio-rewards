<?php

use App\Domain\Provider\Drivers\AioIptvVerificationDriver;
use App\Domain\Provider\Drivers\FakeVerificationDriver;

return [

    /*
    |--------------------------------------------------------------------------
    | Active verification driver
    |--------------------------------------------------------------------------
    | Which driver key resolves the CustomerVerificationContract binding.
    | Use "fake" in local/testing/preview environments so the activation flow
    | can be exercised end-to-end without real provider credentials.
    */
    'driver' => env('PROVIDER_VERIFICATION_DRIVER', 'fake'),

    'drivers' => [
        'fake' => [
            'class' => FakeVerificationDriver::class,
        ],

        'aio_iptv_v1' => [
            'class' => AioIptvVerificationDriver::class,
            'url' => env('PROVIDER_VERIFICATION_URL'),
            'api_key' => env('PROVIDER_VERIFICATION_KEY'),
            'timeout' => (int) env('PROVIDER_VERIFICATION_TIMEOUT', 8),
        ],
    ],
];
