<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Safety net: ensure encryption-dependent tests always have an APP_KEY
     * without reading or embedding a committed / production key.
     *
     * Prefer tests/bootstrap.php (process-wide). This covers app boots that
     * bypass the standard PHPUnit bootstrap path.
     */
    public function createApplication()
    {
        $existing = $_ENV['APP_KEY'] ?? $_SERVER['APP_KEY'] ?? getenv('APP_KEY') ?: null;

        if (! is_string($existing) || $existing === '') {
            $key = 'base64:'.base64_encode(random_bytes(32));
            putenv('APP_KEY='.$key);
            $_ENV['APP_KEY'] = $key;
            $_SERVER['APP_KEY'] = $key;
        }

        return parent::createApplication();
    }
}
