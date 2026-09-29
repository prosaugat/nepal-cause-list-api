<?php

/**
 * Library usage example — no HTTP server needed.
 *
 *   composer install
 *   php examples/fetch.php
 *
 * (The Supreme Court example needs a Selenium hub reachable at SELENIUM_HUB
 *  or http://localhost:4444/wd/hub.)
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use NepalCauseList\CauseListClient;

$client = new CauseListClient();

// Today's BS date.
$today = $client->today();
echo "Today (BS): {$today['formatted']}\n\n";

// 1) A court that needs no id — Special Court, daily list.
$special = $client->fetch('specialcourt', 'daily', '2083-06-12');
printf("Special Court daily: %d benches, %d cases\n", $special['total_benches'], $special['total_cases']);

// 2) A court that needs an id — pick the first high court from the directory.
$highCourts = $client->highCourts();
$firstHigh = $highCourts[0] ?? null;
if ($firstHigh) {
    $id = $firstHigh['id'] ?? $firstHigh['court_id'] ?? null;
    $res = $client->fetch('highcourt', 'weekly', '2083-06-12', (int) $id);
    printf("High Court #%s weekly: %d benches, %d cases\n", $id, $res['total_benches'], $res['total_cases']);
}

// 3) Supreme Court daily (requires Selenium).
try {
    $sc = $client->fetch('supremecourt', 'daily', '2083-06-12');
    printf("Supreme Court daily: %d benches, %d cases\n", $sc['total_benches'], $sc['total_cases']);
} catch (\Throwable $e) {
    echo "Supreme Court skipped (Selenium not available): {$e->getMessage()}\n";
}
