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

    foreach (['/manifest.json', '/sw.js', '/robots.txt', '/offline.html', '/assets/css/app.css'] as $path) {
        assertSame(200, $anonymous->get($path)['status'], $path . ' must stay reachable');
    }
});
