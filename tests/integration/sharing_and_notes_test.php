<?php
// =====================================================
// tests/integration/sharing_and_notes_test.php
//
// The last three controllers without coverage: file transfers (a code anyone
// can redeem), app share links (a token that opens part of an account without
// signing in), and food notes.
// =====================================================

declare(strict_types=1);

function shareClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('shr_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $clients[$label] = $client;
}

// =====================================================
// File transfer — a six-digit code redeemable without an account
// =====================================================

test('sending files returns a code and a link', function (TestClient $_c): void {
    $data = shareClient('sender')->transfer(['note.txt' => 'transfer me']);

    assertSame(200, $data['status'], 'send failed: ' . json_encode($data));
    assertTrue((bool)preg_match('/^\d{6}$/', (string)($data['code'] ?? '')), 'a six-digit code is expected');
    assertStringContains('/transfer/download/', (string)($data['download_url'] ?? ''));
    assertSame(1, count($data['files'] ?? []));
    // The countdown runs from seconds left, not from a wall clock.
    assertTrue(is_int($data['expires_in'] ?? null) && $data['expires_in'] > 0, 'expires_in: ' . json_encode($data['expires_in'] ?? null));
});

test('a transfer code is redeemable without signing in', function (TestClient $_c): void {
    $sent = (shareClient('sender')->transfer(['shared.txt' => 'hello from the other side']));

    // This is the point of the feature: a phone that has never signed in.
    // /transfer itself requires a session, so the token comes from the only
    // page an anonymous visitor can render.
    $stranger = new TestClient(TEST_BASE_URL);
    $stranger->get('/login');
    $received = $stranger->json($stranger->post('/api/transfer/receive', ['code' => $sent['code']]));

    assertTrue($received['ok'] ?? false, 'the code should be redeemable: ' . json_encode($received));
    assertSame('shared.txt', $received['files'][0]['name'] ?? null);
});

test('a malformed or unknown code is refused', function (TestClient $_c): void {
    $stranger = new TestClient(TEST_BASE_URL);
    $stranger->get('/login');

    assertSame(422, $stranger->post('/api/transfer/receive', ['code' => 'abcdef'])['status'], 'letters are not a code');
    assertSame(422, $stranger->post('/api/transfer/receive', ['code' => '123'])['status'], 'too short');
    assertSame(404, $stranger->post('/api/transfer/receive', ['code' => '000000'])['status'], 'no such transfer');
});

test('the listing shows only your own transfers', function (TestClient $_c): void {
    shareClient('sender')->transfer(['mine.txt' => 'x']);

    $mine = shareClient('sender')->json(shareClient('sender')->get('/api/transfer'));
    $theirs = shareClient('other')->json(shareClient('other')->get('/api/transfer'));

    assertTrue(count($mine['transfers'] ?? []) > 0);
    assertSame([], $theirs['transfers'] ?? null, 'a different account starts with none');
});

test('another account cannot delete a transfer', function (TestClient $_c): void {
    $sent = (shareClient('sender')->transfer(['private.txt' => 'x']));

    assertSame(404, shareClient('other')->request('DELETE', '/api/transfer/' . (int)$sent['id'], [])['status']);
});

test('an expired download link answers 410 rather than serving the file', function (TestClient $_c): void {
    $stranger = new TestClient(TEST_BASE_URL);
    $response = $stranger->get('/transfer/download/' . str_repeat('a', 64));

    assertSame(410, $response['status'], 'an unknown token is treated as expired');
});

// =====================================================
// App share links — a token that opens chosen menus read-only
// =====================================================

test('a share link is created for the chosen menus', function (TestClient $_c): void {
    $created = shareClient('owner')->json(shareClient('owner')->post('/api/app-shares', [
        'label' => 'ให้ครอบครัวดู ' . TEST_RUN_ID,
        'menus' => ['tasks', 'notes'],
    ]));

    assertTrue((bool)preg_match('/^[a-f0-9]{64}$/', (string)($created['token'] ?? '')), 'expected a 64-char token');
    assertStringContains('/shared/', (string)($created['link'] ?? ''));
});

test('the share list says which links have run out', function (TestClient $_c): void {
    // expires_at is the server's wall clock; a browser elsewhere cannot compare it.
    require_once ROOT . '/config/database.php';
    $client = shareClient('owner');
    $client->post('/api/app-shares', ['label' => 'ยังใช้ได้ ' . TEST_RUN_ID, 'menus' => ['tasks'], 'expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
    $shares = $client->json($client->get('/api/app-shares'))['shares'] ?? [];

    $live = array_values(array_filter($shares, fn($s) => $s['label'] === 'ยังใช้ได้ ' . TEST_RUN_ID));
    assertSame(1, count($live));
    assertSame(false, $live[0]['is_expired'], 'a link with an hour left is not expired');

    DB::run('UPDATE app_shares SET expires_at = ? WHERE id = ?', [date('Y-m-d H:i:s', time() - 60), $live[0]['id']]);
    $after = array_values(array_filter($client->json($client->get('/api/app-shares'))['shares'], fn($s) => (int)$s['id'] === (int)$live[0]['id']));
    assertSame(true, $after[0]['is_expired'], 'a minute past its expiry it is');
});

test('a share link needs a label and at least one real menu', function (TestClient $_c): void {
    $client = shareClient('owner');

    assertSame(422, $client->post('/api/app-shares', ['label' => '', 'menus' => ['tasks']])['status']);
    assertSame(422, $client->post('/api/app-shares', ['label' => 'x', 'menus' => []])['status']);
    // Unknown names are dropped, which leaves nothing to share.
    assertSame(422, $client->post('/api/app-shares', ['label' => 'x', 'menus' => ['settings', 'admin']])['status']);
});

test('an expiry in the past is refused', function (TestClient $_c): void {
    assertSame(422, shareClient('owner')->post('/api/app-shares', [
        'label' => 'หมดอายุแล้ว', 'menus' => ['tasks'], 'expires_at' => '2020-01-01 00:00:00',
    ])['status']);
});

test('opening a share token grants exactly the menus it names', function (TestClient $_c): void {
    $created = shareClient('owner')->json(shareClient('owner')->post('/api/app-shares', [
        'label' => 'เฉพาะงาน ' . TEST_RUN_ID,
        'menus' => ['tasks'],
    ]));

    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/shared/' . $created['token']);

    assertSame(200, $guest->get('/tasks')['status'], 'the shared menu should open');
    // Anything outside the list stays shut, even in the same session.
    assertContains($guest->get('/finance')['status'], [403, 302], 'an unshared menu must not open');
});

test('share mode is read-only', function (TestClient $_c): void {
    $created = shareClient('owner')->json(shareClient('owner')->post('/api/app-shares', [
        'label' => 'อ่านอย่างเดียว ' . TEST_RUN_ID,
        'menus' => ['tasks'],
    ]));

    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/shared/' . $created['token']);
    $guest->get('/tasks');

    $response = $guest->post('/api/tasks', ['title' => 'แอบเพิ่มงาน', 'quadrant' => 1]);
    assertContains($response['status'], [403, 401], 'a share-mode visitor must not write');
});

/** The controls that add, change or remove something, as the pages print them. */
const WRITE_CONTROL = '/data-act="(open(Add|Create|Edit)\w*|save\w*|delete\w*|add\w*|import\w*|renew\w*)"|class="add-row"|class="quick-add"|id="noteTitle"/';

test('a shared page prints no control that would change data', function (TestClient $_c): void {
    // docs/REDESIGN.md §2.6: not hidden by CSS, not printed at all.
    $owner = shareClient('owner');
    $note = $owner->json($owner->post('/api/notes', ['title' => 'โน้ตที่แชร์ ' . TEST_RUN_ID, 'is_encrypted' => false]))['note'];
    $pages = ['/tasks', '/notes', '/notes/' . $note['id'], '/planner', '/focus', '/exercise', '/food-notes', '/finance', '/subscriptions', '/stocks'];

    // The pattern does find them on the owner's own pages.
    foreach (['/tasks', '/notes/' . $note['id'], '/finance'] as $path) {
        assertTrue(preg_match(WRITE_CONTROL, $owner->get($path)['body']) === 1, 'the owner sees the controls on ' . $path);
    }

    $created = $owner->json($owner->post('/api/app-shares', [
        'label' => 'ทุกเมนู ' . TEST_RUN_ID,
        'menus' => ['tasks', 'notes', 'planner', 'focus', 'exercise', 'food-notes', 'finance', 'subscriptions', 'stocks'],
    ]));
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/shared/' . $created['token']);

    foreach ($pages as $path) {
        $page = $guest->get($path);
        assertSame(200, $page['status'], $path . ' should open on the link');
        assertTrue(preg_match(WRITE_CONTROL, $page['body'], $found) === 0, $path . ' still prints ' . ($found[0] ?? ''));
    }
});

test('an unknown share token shows the expired page, not the account', function (TestClient $_c): void {
    $guest = new TestClient(TEST_BASE_URL);
    $response = $guest->get('/shared/' . str_repeat('f', 64));

    assertSame(410, $response['status'], 'a dead link is Gone, not a redirect into share mode');

    // And no share session was established on the way past.
    assertContains($guest->get('/tasks')['status'], [302, 403], 'the visitor must still have no access');
});

test('a share link installs as its own app that opens the link again', function (TestClient $_c): void {
    $created = shareClient('owner')->json(shareClient('owner')->post('/api/app-shares', [
        'label' => 'แอปของลิงก์ ' . TEST_RUN_ID,
        'menus' => ['tasks'],
    ]));
    $token = $created['token'];

    // หน้าแชร์ชี้ไปที่ manifest ของลิงก์นั้น ส่วนหน้าของเจ้าของยังใช้ manifest หลัก (REQ-10)
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/shared/' . $token);
    assertStringContains('/shared/' . $token . '/manifest.webmanifest', $guest->get('/tasks')['body']);
    assertStringContains('/manifest.json"', shareClient('owner')->get('/tasks')['body']);

    // มือถือที่ไม่มี cookie เลยก็อ่าน manifest ได้ และ token อยู่ใน start_url (REQ-8)
    $manifest = json_decode((new TestClient(TEST_BASE_URL))->get('/shared/' . $token . '/manifest.webmanifest')['body'], true);
    assertStringContains('/shared/' . $token, (string)$manifest['start_url']);
    assertSame($manifest['start_url'], $manifest['id'], 'its own id, so it sits beside the owner\'s DayFlow icon');
    assertSame('DayFlow · แอปของลิงก์ ' . TEST_RUN_ID, $manifest['name']);

    assertSame(404, (new TestClient(TEST_BASE_URL))->get('/shared/' . str_repeat('f', 64) . '/manifest.webmanifest')['status']);
});

// คนดูลิงก์แชร์หุ้นเห็นแค่ยอดรวม เงินสด และรูป รายการซื้อขายกับเงินลงทุนรายครั้งไม่เปิดผ่าน API (CR-1)
test('a share link does not hand out the trade or capital lists', function (TestClient $_c): void {
    $created = shareClient('owner')->json(shareClient('owner')->post('/api/app-shares', [
        'label' => 'หุ้น ' . TEST_RUN_ID,
        'menus' => ['stocks'],
    ]));

    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/shared/' . $created['token']);

    assertSame(200, $guest->get('/api/stocks/summary')['status'], 'the totals the shared page shows still load');
    assertSame(403, $guest->get('/api/stocks')['status'], 'trades stay private');
    assertSame(403, $guest->get('/api/stocks/capital')['status'], 'capital rows stay private');
});

test('another account cannot delete a share link', function (TestClient $_c): void {
    shareClient('owner')->post('/api/app-shares', ['label' => 'ของฉัน ' . TEST_RUN_ID, 'menus' => ['notes']]);
    $shares = shareClient('owner')->json(shareClient('owner')->get('/api/app-shares'))['shares'] ?? [];
    assertTrue($shares !== [], 'the owner should have a share to protect');

    $id = (int)$shares[0]['id'];
    assertSame(404, shareClient('other')->request('DELETE', "/api/app-shares/{$id}", [])['status']);
});

// =====================================================
// Food notes
// =====================================================

test('a food note is created, listed and summarised', function (TestClient $_c): void {
    $client = shareClient('food');

    $created = $client->json($client->post('/api/food-notes', [
        'name' => 'ข้าวมันไก่ ' . TEST_RUN_ID, 'type' => 'food', 'reaction' => 'allergy',
    ]));
    assertTrue(!empty($created['item']['id']), 'creation should return the item');

    $list = $client->json($client->get('/api/food-notes'));
    assertArrayHasKey('items', $list);
    assertArrayHasKey('summary', $list);
});

test('a food note needs a name', function (TestClient $_c): void {
    assertSame(422, shareClient('food')->post('/api/food-notes', ['name' => '', 'type' => 'food'])['status']);
});

test('a food note belonging to someone else is out of reach', function (TestClient $_c): void {
    $client = shareClient('food');
    $item = $client->json($client->post('/api/food-notes', [
        'name' => 'ของส่วนตัว ' . TEST_RUN_ID, 'type' => 'food', 'reaction' => 'allergy',
    ]))['item'];

    $other = shareClient('other');
    assertSame(404, $other->request('PUT', '/api/food-notes/' . (int)$item['id'], [
        'name' => 'แก้โดยคนอื่น', 'type' => 'food',
    ])['status']);
    assertSame(404, $other->request('DELETE', '/api/food-notes/' . (int)$item['id'], [])['status']);
});

test('these endpoints need a session', function (TestClient $_c): void {
    $anonymous = new TestClient(TEST_BASE_URL);

    assertContains($anonymous->get('/api/food-notes')['status'], [401, 302]);
    assertContains($anonymous->get('/api/app-shares')['status'], [401, 302]);
    assertContains($anonymous->get('/api/transfer')['status'], [401, 302]);
});
