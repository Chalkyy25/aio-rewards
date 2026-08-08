<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| PHPUnit bootstrap
|--------------------------------------------------------------------------
|
| Loads Composer and installs an ephemeral APP_KEY for this test process
| only. The key is generated at runtime (never committed) so encryption-
| dependent Feature tests stay hermetic without embedding a Laravel
| APP_KEY in PHPUnit XML (which GitGuardian correctly rejects).
|
| Always mint a throwaway key for the process so tests never inherit a
| local or production APP_KEY from the environment / .env. Dotenv will not
| overwrite an already-set variable (immutable by default).
|
*/

require dirname(__DIR__).'/vendor/autoload.php';

$key = 'base64:'.base64_encode(random_bytes(32));
putenv('APP_KEY='.$key);
$_ENV['APP_KEY'] = $key;
$_SERVER['APP_KEY'] = $key;
