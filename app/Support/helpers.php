<?php

use App\Domain\Settings\SettingsRepository;

if (! function_exists('settings')) {
    /**
     * Fetch a setting value with default fallback, or return the repository
     * when called with no arguments.
     */
    function settings(?string $key = null, ?string $default = null): mixed
    {
        /** @var SettingsRepository $repo */
        $repo = app(SettingsRepository::class);

        if ($key === null) {
            return $repo;
        }

        return $repo->get($key, $default ?? $repo->default($key));
    }
}
