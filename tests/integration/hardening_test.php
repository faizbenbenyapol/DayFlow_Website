<?php
// =====================================================
// tests/integration/hardening_test.php
//
// Regression cover for three holes found in review:
//   - a share link reached the owner's settings (full data export) and the
//     dashboard summary, neither of which it was meant to open;
//   - the JSON import pasted the file's keys into SQL as column names, and
//     let a note block attach itself to someone else's note;
//   - the document root is the whole repository, so tooling, tests and
//     deployment files were served (or run) by URL.
// =====================================================

declare(strict_types=1);

function hardeningClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('hard_' . $label, 'TestPass123!');
    return $clients[$label] = $client;
}

/** A guest session opened by a fresh share link that covers only tasks. */
function hardeningShareGuest(): TestClient
{
    $owner = hardeningClient('owner');
    $created = $owner->json($owner->post('/api/app-shares', [
        'label' => 'เฉพาะงาน ' . TEST_RUN_ID,
        'menus' => ['tasks'],
    ]));

    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/shared/' . $created['token']);
    return $guest;
}

function hardeningImport(TestClient $client, array $data): array
{
    $client->get('/settings'); // a fresh CSRF token for the multipart post
    return $client->upload('/api/settings/import', 'file', 'backup.json', json_encode($data, JSON_UNESCAPED_UNICODE));
}

// =====================================================
// Query strings
// =====================================================

test('an array where a query word belongs is a bad request, not a crash', function (TestClient $client): void {
    // ?month[]=1 used to reach trim() as an array and end in a 500.
    $source = (string)file_get_contents(ROOT . '/core/Router.php');
    preg_match_all("#add\('GET',\s*'(/api/[a-z0-9/_-]+)'#", $source, $m);
    preg_match_all("#Request::query\('([a-z_]+)'#", implode('', array_map('file_get_contents', glob(ROOT . '/controllers/*.php'))), $q);

    $query = implode('&', array_map(fn(string $name): string => $name . '[]=1', array_unique($q[1])));
    assertTrue(count($m[1]) > 20 && $query !== '', 'the routes and parameters were found');

    $crashed = [];
    foreach (array_unique($m[1]) as $path) {
        $status = $client->get($path . '?' . $query)['status'];
        if ($status >= 500) $crashed[] = "$path ($status)";
    }
    assertSame([], $crashed, 'these crashed');
});

test('an array where a body field belongs is refused, not a crash', function (TestClient $_c): void {
    // JSON lets any field be a list; trim() or (string) on one threw a 500.
    // A fresh account, since some of these calls really write.
    $client = hardeningClient('arrays');
    $source = (string)file_get_contents(ROOT . '/core/Router.php');
    preg_match_all("#add\('POST',\s*'(/api/[a-z0-9/_-]+)'#", $source, $m);
    preg_match_all("#Request::(?:input|rawInput)\('([a-z_]+)'#", implode('', array_map('file_get_contents', glob(ROOT . '/controllers/*.php'))), $f);

    // Sign-in, credentials, account removal and calls that leave the server
    // (push answers 503 on purpose until VAPID keys are set).
    $skip = '#^/api/(auth/|settings/(delete|password|two-factor|devices|telegram/test|import)|ai/|push/|stocks/(refresh|analyze|keys/test))#';
    $body = array_fill_keys(array_unique($f[1]), ['x']);
    assertTrue(count($m[1]) > 30 && count($body) > 50, 'the routes and fields were found');

    $client->get('/settings');   // a fresh CSRF token
    $crashed = [];
    foreach (array_unique($m[1]) as $path) {
        if (preg_match($skip, $path)) continue;
        $status = $client->post($path, $body)['status'];
        if ($status >= 500) $crashed[] = "$path ($status)";
    }
    assertSame([], $crashed, 'these crashed');
});

// =====================================================
// Share links
// =====================================================

test('a share link cannot export the owner\'s data', function (TestClient $_c): void {
    $guest = hardeningShareGuest();

    assertSame(403, $guest->get('/api/settings/export')['status'], 'the export holds the whole account');
    assertSame(403, $guest->get('/api/settings')['status']);
    assertSame(403, $guest->get('/api/settings/devices')['status']);
    assertSame(403, $guest->get('/settings')['status']);
});

test('a share link cannot read the dashboard summary', function (TestClient $_c): void {
    $guest = hardeningShareGuest();

    assertSame(403, $guest->get('/api/dashboard/summary')['status'], 'the summary spans every module');
});

test('the root of a share session lands on a shared menu', function (TestClient $_c): void {
    $guest = hardeningShareGuest();
    $response = $guest->get('/');

    assertSame(302, $response['status']);
    assertTrue((bool)preg_match('~^Location: \S*/tasks\s*$~mi', $response['headers']), 'expected a redirect to /tasks');
    // The shared menu itself still opens.
    assertSame(200, $guest->get('/tasks')['status']);
});

// =====================================================
// Import
// =====================================================

test('import ignores keys that are not real columns', function (TestClient $_c): void {
    $client = hardeningClient('importer');
    $title = 'นำเข้าปลอดภัย ' . TEST_RUN_ID;

    $response = hardeningImport($client, ['notes' => [[
        'title' => $title,
        'title`) SELECT password_hash FROM users -- ' => 'x',
    ]]]);
    assertSame(200, $response['status'], 'the unknown key should be dropped: ' . substr($response['body'], 0, 200));

    $notes = $client->json($client->get('/api/notes'))['notes'] ?? [];
    assertSame([$title], array_column($notes, 'title'));
});

test('import refuses a value that is not a scalar', function (TestClient $_c): void {
    $response = hardeningImport(hardeningClient('importer'), ['notes' => [['title' => ['nested']]]]);

    assertSame(422, $response['status']);
});

test('an imported note block cannot land in someone else\'s note', function (TestClient $_c): void {
    $victim = hardeningClient('victim');
    $note = $victim->json($victim->post('/api/notes', ['title' => 'ของเหยื่อ ' . TEST_RUN_ID, 'is_encrypted' => false]))['note'];

    $response = hardeningImport(hardeningClient('importer'), ['note_blocks' => [[
        'note_id' => (int)$note['id'], 'type' => 'text', 'content' => 'แทรกจากบัญชีอื่น', 'position' => 0,
    ]]]);
    assertSame(200, $response['status']);

    $blocks = $victim->json($victim->get('/api/notes/' . (int)$note['id'] . '/blocks'))['blocks'] ?? null;
    assertSame([], $blocks, 'the victim\'s note must stay untouched');
});

test('an export imports back with its blocks intact', function (TestClient $_c): void {
    $client = hardeningClient('roundtrip');
    $note = $client->json($client->post('/api/notes', ['title' => 'ไปกลับ ' . TEST_RUN_ID, 'is_encrypted' => false]))['note'];
    $client->post('/api/notes/' . (int)$note['id'] . '/blocks', ['type' => 'text', 'content' => 'ยังอยู่ครบ']);

    $export = $client->json($client->get('/api/settings/export'));
    assertSame(200, hardeningImport($client, $export)['status']);

    // An import hands out fresh ids, so find the restored note by its title.
    $notes = $client->json($client->get('/api/notes'))['notes'] ?? [];
    $restored = array_values(array_filter($notes, fn($n) => $n['title'] === $note['title']))[0] ?? null;
    assertTrue($restored !== null, 'the note should be restored');

    $blocks = $client->json($client->get('/api/notes/' . (int)$restored['id'] . '/blocks'))['blocks'] ?? [];
    assertContains('ยังอยู่ครบ', array_column($blocks, 'content'), 'a block of an imported note must survive');
});

// =====================================================
// Files outside the site
// =====================================================

test('repository files are not served', function (TestClient $_c): void {
    $anonymous = new TestClient(TEST_BASE_URL);

    foreach ([
        '/README.md', '/docker-compose.yml', '/Dockerfile', '/Caddyfile', '/cron.php',
        '/.env.example', '/.gitignore', '/.github/workflows/ci.yml',
        '/scripts/migrate.php', '/scripts/smoke.php', '/tests/run.php', '/tests/bootstrap.php',
        '/sql/migrations/001_schema.sql', '/docker/php.ini', '/storage/logs/php-error.log',
        '/config/config.php', '/models/User.php', '/views/layout/header.php', '/.env', '/.git/config',
        '/public/index.php', '/public/.htaccess',
    ] as $path) {
        assertNotServed($anonymous, $path);
    }
});

test('the site\'s own public files are still served', function (TestClient $_c): void {
    $anonymous = new TestClient(TEST_BASE_URL);

    foreach (['/manifest.json', '/sw.js', '/robots.txt', '/offline.html', '/assets/css/components.css', '/assets/css/modules/outside.css', '/assets/js/standalone.js', '/assets/icons/icon-512.png'] as $path) {
        assertSame(200, $anonymous->get($path)['status'], $path . ' must stay reachable');
    }
});
