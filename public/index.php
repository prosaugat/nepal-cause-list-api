<?php

/**
 * Front controller for the Nepal Cause List API.
 *
 * Local dev:   composer serve   (php -S localhost:8080 -t public)
 * Docker:      docker compose up
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use NepalCauseList\Http\Api;
use NepalCauseList\Support\FileCache;

// Optional: point Selenium at a custom hub (Docker sets this automatically).
$seleniumHub = getenv('SELENIUM_HUB') ?: null;
if ($seleniumHub) {
    // SupremeCourtService reads the hub URL from this env at request time.
    putenv('SELENIUM_HUB=' . $seleniumHub);
}

$cacheDir = getenv('CACHE_DIR') ?: null;
$ttl = (int) (getenv('CACHE_TTL') ?: 1800);

$api = new Api(null, new FileCache($cacheDir), $ttl);
$api->handle();
