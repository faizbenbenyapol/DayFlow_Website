<?php
// =====================================================
// tests/integration/abuse_test.php
//
// The public edges of the app: the shared demo account, and the transfer code
// anyone can redeem without signing in.
// =====================================================

declare(strict_types=1);

/**
 * sql/migrations/018_demo_account.sql only flags an account someone registered by
 * hand, so a fresh test database has none. Register it over HTTP, then set the
 * flag directly — the suites run inside the app container, next to the DB.
 */
function ensureDemoAccount(): void
{
    static $done = false;
    if ($done) return;

    $registrar = new TestClient(TEST_BASE_URL);
    $registrar->get('/login');
    $registrar->post('/api/auth/register', [
        'username' => 'demo', 'email' => 'demo@example.test',
        'password' => 'DemoPass123!', 'confirm_password' => 'DemoPass123!', 'display_name' => 'Demo',
    ]);

    require_once ROOT . '/config/database.php';
    if (!DB::run('SELECT 1 FROM users WHERE is_demo = 1 LIMIT 1')->fetchColumn()) {
        DB::run("UPDATE users SET is_demo = 1 WHERE username = 'demo'");
    }
    $done = true;
}

/** A fresh visitor signed in through /demo, holding a CSRF token. */
function demoVisitor(): TestClient
{
    ensureDemoAccount();
    $visitor = new TestClient(TEST_BASE_URL);
    $response = $visitor->get('/demo');
    assertSame(302, $response['status'], 'the demo account should exist in the test database');
    assertFalse(str_contains($response['headers'], '/login'), 'expected /demo to sign in, not bounce to /login');
    $visitor->get('/');
    return $visitor;
}

/** A client that the app sees as coming from its own address. */
function clientFrom(string $ip): TestClient
{
    $client = new TestClient(TEST_BASE_URL);
    $client->headers = ['X-Forwarded-For: ' . $ip];
    $client->get('/login');
    return $client;
}

// =====================================================
// Demo account
// =====================================================

test('the demo account can still try everyday features', function (TestClient $_c): void {
    $visitor = demoVisitor();

    $response = $visitor->post('/api/tasks', ['title' => 'ลองใช้ ' . TEST_RUN_ID, 'quadrant' => 1]);
    assertContains($response['status'], [200, 201], 'a demo visitor should be able to add a task');

    $task = $visitor->json($response)['task'] ?? null;
    if ($task) $visitor->request('DELETE', '/api/tasks/' . (int)$task['id'], []);
});

test('the demo account cannot mint public links or store files', function (TestClient $_c): void {
    $visitor = demoVisitor();

    assertSame(403, $visitor->post('/api/app-shares', ['label' => 'x', 'menus' => ['tasks']])['status'], 'app share');
    assertSame(403, $visitor->post('/api/shares', ['file_id' => 1])['status'], 'file share');
    assertSame(403, $visitor->post('/api/files/folder', ['name' => 'x'])['status'], 'folder');
    assertSame(403, $visitor->upload('/api/files/upload', 'file', 'x.txt', 'x')['status'], 'upload');
    assertSame(403, $visitor->uploadMany('/api/transfer/send', 'files', [
        ['name' => 'x.txt', 'contents' => 'x', 'type' => 'text/plain'],
    ])['status'], 'transfer');
});

test('the demo account cannot change its settings or keys', function (TestClient $_c): void {
    $visitor = demoVisitor();

    assertSame(403, $visitor->post('/api/settings/theme', ['theme' => 'dark'])['status'], 'theme');
    assertSame(403, $visitor->post('/api/settings/password', ['current_password' => 'x', 'new_password' => 'y'])['status'], 'password');
    assertSame(403, $visitor->post('/api/settings/two-factor/begin', [])['status'], '2fa');
    assertSame(403, $visitor->upload('/api/settings/import', 'file', 'backup.json', '{}')['status'], 'import');
    assertSame(403, $visitor->post('/api/ai/keys', ['provider' => 'gemini', 'api_key' => 'x'])['status'], 'ai key');
    assertSame(403, $visitor->post('/api/stocks/keys', ['provider' => 'x', 'api_key' => 'x'])['status'], 'stock key');
});

test('a normal account is not caught by the demo restrictions', function (TestClient $client): void {
    $response = $client->post('/api/settings/theme', ['theme' => 'light']);
    assertSame(200, $response['status'], 'a real account keeps its settings: ' . substr($response['body'], 0, 200));
});

// =====================================================
// Transfer codes
// =====================================================

test('guessing transfer codes is throttled per client', function (TestClient $_c): void {
    $guesser = clientFrom('203.0.113.' . random_int(1, 254));

    $statuses = [];
    for ($i = 0; $i < 31; $i++) {
        $statuses[] = $guesser->post('/api/transfer/receive', ['code' => sprintf('%06d', $i)])['status'];
    }

    assertSame(404, $statuses[0], 'the first wrong guess is just wrong');
    assertSame(429, $statuses[30], 'the 31st guess in ten minutes is refused');

    // The limit belongs to that client, not to everyone behind the proxy.
    $bystander = clientFrom('198.51.100.' . random_int(1, 254));
    assertSame(404, $bystander->post('/api/transfer/receive', ['code' => '000000'])['status']);
});
