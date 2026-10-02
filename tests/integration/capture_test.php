<?php
// =====================================================
// tests/integration/capture_test.php — Bookmarks, Quick notes and Focus stats
//
// Small modules, but they take free text and URLs from the user and show them
// back, so the limits and the account boundary are worth pinning down.
// =====================================================

declare(strict_types=1);

function captureClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('cap_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $clients[$label] = $client;
}

// --- Bookmarks ---

test('a bookmark is saved and listed with its category', function (TestClient $_c): void {
    $client = captureClient('bm');
    $created = $client->post('/api/bookmarks', ['title' => 'PHP Manual', 'url' => 'https://www.php.net/manual', 'category' => 'เอกสาร']);
    assertSame(201, $created['status']);

    $list = $client->json($client->get('/api/bookmarks'))['bookmarks'];
    assertSame(1, count($list));
    assertSame('https://www.php.net/manual', $list[0]['url']);
    assertSame('เอกสาร', $list[0]['category']);
});

test('a bookmark without a category lands in the default one', function (TestClient $_c): void {
    $client = captureClient('bmdefault');
    $client->post('/api/bookmarks', ['title' => 'A', 'url' => 'https://example.com', 'category' => '   ']);
    assertSame('ทั่วไป', $client->json($client->get('/api/bookmarks'))['bookmarks'][0]['category']);
});

test('only http and https links are accepted', function (TestClient $_c): void {
    $client = captureClient('bmscheme');

    $bad = [
        'javascript:alert(1)', 'data:text/html,<script>1</script>', 'file:///etc/passwd', 'ftp://example.com/x',
        'example.com', '//example.com', 'https://', 'https://exa mple.com',
    ];
    foreach ($bad as $url) {
        assertSame(422, $client->post('/api/bookmarks', ['title' => 'x', 'url' => $url])['status'], $url . ' should be refused');
    }
    assertSame(0, count($client->json($client->get('/api/bookmarks'))['bookmarks']));
});

test('the title, category and url limits are enforced without a server error', function (TestClient $_c): void {
    $client = captureClient('bmlimits');

    assertSame(422, $client->post('/api/bookmarks', ['title' => '', 'url' => 'https://example.com'])['status'], 'no title');
    assertSame(422, $client->post('/api/bookmarks', ['title' => str_repeat('ก', 181), 'url' => 'https://example.com'])['status'], 'title too long');

    // The column holds 2048 characters; a longer link must be refused, not crash the insert.
    $long = 'https://example.com/' . str_repeat('a', 2100);
    assertSame(422, $client->post('/api/bookmarks', ['title' => 'long', 'url' => $long])['status'], 'url too long');

    // A category over the column width is trimmed rather than failing.
    $response = $client->post('/api/bookmarks', ['title' => 'ok', 'url' => 'https://example.com', 'category' => str_repeat('ข', 200)]);
    assertSame(201, $response['status']);
});

test('markup in a bookmark is stored as typed', function (TestClient $_c): void {
    $client = captureClient('bmxss');
    $client->post('/api/bookmarks', ['title' => '<img src=x onerror=1>', 'url' => 'https://example.com/?a=1&b=2']);
    $row = $client->json($client->get('/api/bookmarks'))['bookmarks'][0];
    assertSame('<img src=x onerror=1>', $row['title'], 'escaped when shown, not mangled on the way in');
});

test('a bookmark can be deleted only by its owner', function (TestClient $_c): void {
    $owner = captureClient('bmowner');
    $other = captureClient('bmother');
    $id = $owner->json($owner->post('/api/bookmarks', ['title' => 'mine', 'url' => 'https://example.com']))['id'];

    assertSame(0, count($other->json($other->get('/api/bookmarks'))['bookmarks']), 'not listed for others');
    assertSame(404, $other->request('DELETE', '/api/bookmarks/' . $id, [])['status']);
    assertSame(1, count($owner->json($owner->get('/api/bookmarks'))['bookmarks']));
    assertSame(200, $owner->request('DELETE', '/api/bookmarks/' . $id, [])['status']);
    assertSame(404, $owner->request('DELETE', '/api/bookmarks/' . $id, [])['status'], 'already gone');
});

// --- Quick notes ---

test('a quick note is added, ticked, unticked and removed', function (TestClient $_c): void {
    $client = captureClient('quick');
    $id = $client->json($client->post('/api/quick-items', ['content' => 'ซื้อนม']))['id'];

    $item = $client->json($client->get('/api/quick-items'))['items'][0];
    assertSame('ซื้อนม', $item['content']);
    assertSame(0, (int)$item['is_done']);

    $client->post('/api/quick-items/' . $id . '/toggle', []);
    assertSame(1, (int)$client->json($client->get('/api/quick-items'))['items'][0]['is_done']);
    $client->post('/api/quick-items/' . $id . '/toggle', []);
    assertSame(0, (int)$client->json($client->get('/api/quick-items'))['items'][0]['is_done'], 'toggling twice returns to open');

    assertSame(200, $client->request('DELETE', '/api/quick-items/' . $id, [])['status']);
    assertSame(0, count($client->json($client->get('/api/quick-items'))['items']));
});

test('a quick note is 1 to 500 characters', function (TestClient $_c): void {
    $client = captureClient('quicklimit');

    assertSame(422, $client->post('/api/quick-items', ['content' => ''])['status'], 'empty');
    assertSame(422, $client->post('/api/quick-items', ['content' => "  \n "])['status'], 'blank');
    assertSame(422, $client->post('/api/quick-items', ['content' => str_repeat('ก', 501)])['status'], 'too long');
    assertSame(201, $client->post('/api/quick-items', ['content' => str_repeat('ก', 500)])['status'], 'exactly the limit');
});

test('open quick notes are listed before finished ones', function (TestClient $_c): void {
    $client = captureClient('quickorder');
    $first = $client->json($client->post('/api/quick-items', ['content' => 'first']))['id'];
    $client->post('/api/quick-items', ['content' => 'second']);
    $client->post('/api/quick-items/' . $first . '/toggle', []);

    $contents = array_column($client->json($client->get('/api/quick-items'))['items'], 'content');
    assertSame(['second', 'first'], $contents);
});

test('one account cannot tick or delete another account\'s quick notes', function (TestClient $_c): void {
    $owner = captureClient('quickowner');
    $other = captureClient('quickother');
    $id = $owner->json($owner->post('/api/quick-items', ['content' => 'private']))['id'];

    assertSame(404, $other->post('/api/quick-items/' . $id . '/toggle', [])['status'], 'toggle');
    assertSame(404, $other->request('DELETE', '/api/quick-items/' . $id, [])['status'], 'delete');
    $item = $owner->json($owner->get('/api/quick-items'))['items'][0];
    assertSame(0, (int)$item['is_done'], 'the owner\'s note is untouched');
});

// --- Focus statistics ---

test('only work sessions count towards today\'s focus time', function (TestClient $_c): void {
    $client = captureClient('focus');
    $client->post('/api/focus', ['type' => 'work', 'duration_min' => 25]);
    $client->post('/api/focus', ['type' => 'work', 'duration_min' => 50]);
    $client->post('/api/focus', ['type' => 'short_break', 'duration_min' => 5]);

    $stats = $client->json($client->get('/api/focus'))['stats'];
    assertSame(75, (int)$stats['today_work_minutes'], 'breaks are not focus time');
    assertSame(2, (int)$stats['today_work_sessions']);
    assertSame(3, (int)$stats['total_sessions_count'], 'but every session is counted in the total');
});

test('a long title is trimmed to fit and a missing one is named for its type', function (TestClient $_c): void {
    $client = captureClient('focustitle');
    $client->post('/api/focus', ['type' => 'work', 'duration_min' => 25, 'title' => str_repeat('ก', 400)]);
    $client->post('/api/focus', ['type' => 'long_break', 'duration_min' => 15]);

    $titles = array_column($client->json($client->get('/api/focus'))['sessions'], 'title');
    assertContains('พักระยะยาว', $titles);
    assertSame(255, max(array_map('mb_strlen', $titles)), 'trimmed to the column width, not rejected');
});

test('these endpoints answer only to a signed-in session', function (TestClient $_c): void {
    $anonymous = new TestClient(TEST_BASE_URL);
    foreach (['/api/bookmarks', '/api/quick-items', '/api/focus'] as $path) {
        assertTrue(in_array($anonymous->get($path)['status'], [401, 302], true), $path);
    }
});
