<?php
// =====================================================
// tests/run.php — test runner
//
//   php tests/run.php                      unit tests only
//   php tests/run.php http://localhost:80  unit + integration tests
//
// Integration suites drive the app over HTTP with a real session, so they need
// a running instance pointed at a throwaway database.
// =====================================================

declare(strict_types=1);

define('ROOT', dirname(__DIR__));

// config.php refuses to hand out an app key in production, and the helper tests
// exercise appEncrypt/appDecrypt. putenv() wins because loadDotEnv() and
// envValue() both leave an already-set variable alone.
if (getenv('APP_ENV') === false) putenv('APP_ENV=development');
if (getenv('APP_KEY') === false) putenv('APP_KEY=' . str_repeat('ab', 32));

require_once ROOT . '/config/config.php';
require_once ROOT . '/core/Ics.php';
require_once ROOT . '/core/Totp.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/TestClient.php';

$baseUrl = $argv[1] ?? getenv('TEST_BASE_URL') ?: '';
define('TEST_BASE_URL', (string)$baseUrl);

// Integration suites share one long-lived account, so rows from an earlier run
// are still there. Tagging the data a run creates keeps each run's assertions
// counting only its own rows.
define('TEST_RUN_ID', substr(bin2hex(random_bytes(4)), 0, 8));

$suites = [];
foreach (['unit', 'integration'] as $kind) {
    if ($kind === 'integration' && TEST_BASE_URL === '') continue;
    foreach (glob(__DIR__ . '/' . $kind . '/*_test.php') ?: [] as $file) {
        $suites[] = [$kind, basename($file, '_test.php'), $file];
    }
}

if ($suites === []) {
    fwrite(STDERR, "No test suites found.\n");
    exit(1);
}

// One signed-in client is shared by every integration case, which keeps the
// suite fast and mirrors how a real session is reused across requests.
$client = null;
if (TEST_BASE_URL !== '') {
    try {
        $client = new TestClient(TEST_BASE_URL);
        $client->login('dayflow_test_user', 'TestPass123!');
    } catch (Throwable $e) {
        fwrite(STDERR, "Cannot reach the app at " . TEST_BASE_URL . ": {$e->getMessage()}\n");
        exit(1);
    }
}

$passed = 0;
$failures = [];
$started = microtime(true);

foreach ($suites as [$kind, $name, $file]) {
    TestRegistry::$currentSuite = $kind . '/' . $name;
    TestRegistry::$cases[TestRegistry::$currentSuite] = [];
    require $file;

    echo "\n" . TestRegistry::$currentSuite . "\n";
    foreach (TestRegistry::$cases[TestRegistry::$currentSuite] as $caseName => $case) {
        try {
            $case($client);
            $passed++;
            echo "  \xE2\x9C\x93 {$caseName}\n";
        } catch (Throwable $e) {
            $label = TestRegistry::$currentSuite . ' :: ' . $caseName;
            $failures[] = [$label, $e];
            echo "  \xE2\x9C\x97 {$caseName}\n";
        }
    }
}

$elapsed = round((microtime(true) - $started) * 1000);

if ($failures !== []) {
    echo "\n" . str_repeat('-', 60) . "\nFAILURES\n";
    foreach ($failures as [$label, $e]) {
        echo "\n{$label}\n  {$e->getMessage()}\n";
        if (!$e instanceof TestFailure) {
            echo '  at ' . str_replace(ROOT . DIRECTORY_SEPARATOR, '', $e->getFile()) . ':' . $e->getLine() . "\n";
        }
    }
}

echo "\n" . str_repeat('-', 60) . "\n";
printf("%d passed, %d failed  (%d ms)\n", $passed, count($failures), $elapsed);

if (TEST_BASE_URL === '') {
    echo "Integration suites skipped — pass a base URL to include them.\n";
}

exit($failures === [] ? 0 : 1);
