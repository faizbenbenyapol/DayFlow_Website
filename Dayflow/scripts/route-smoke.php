<?php
/**
 * Lightweight route smoke test for the local Docker stack.
 * Protected pages are expected to redirect to login; a 404/500 is a failure.
 */

$baseUrl = rtrim($argv[1] ?? 'http://localhost', '/');
$routes = [
    '/health' => [200],
    '/login' => [200],
    '/api/dashboard/summary' => [401, 302],
    '/api/search?q=test' => [401, 302],
    '/api/habits' => [401, 302],
    '/api/quick-items' => [401, 302],
    '/api/bookmarks' => [401, 302],
    '/' => [200, 302],
    '/tasks' => [200, 302],
    '/notes' => [200, 302],
    '/planner' => [200, 302],
    '/projects' => [200, 302],
    '/exercise' => [200, 302],
    '/finance' => [200, 302],
    '/subscriptions' => [200, 302],
    '/files' => [200, 302],
    '/settings' => [200, 302],
    '/food-notes' => [200, 302],
    '/calculator' => [200, 302],
    '/ai' => [200, 302],
    '/file-tools' => [200, 302],
    '/transfer' => [200, 302],
    '/skills' => [200, 302],
    '/focus' => [200, 302],
    '/habits' => [200, 302],
    '/quick-notes' => [200, 302],
    '/bookmarks' => [200, 302],
    '/stocks' => [200, 302],
];

$failures = 0;
foreach ($routes as $route => $expected) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'ignore_errors' => true,
            'timeout' => 10,
            'follow_location' => 0,
        ],
    ]);
    $body = @file_get_contents($baseUrl . $route, false, $context);
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $match)) {
            $status = (int)$match[1];
            break;
        }
    }

    $ok = in_array($status, $expected, true);
    printf("[%s] %-22s HTTP %d\n", $ok ? 'OK' : 'FAIL', $route, $status);
    if (!$ok) {
        $failures++;
    }
}

if ($failures > 0) {
    fwrite(STDERR, "Route smoke failed: {$failures} route(s)\n");
    exit(1);
}

echo "Route smoke passed: " . count($routes) . " routes\n";
