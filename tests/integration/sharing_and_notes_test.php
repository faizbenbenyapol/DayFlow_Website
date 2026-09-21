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
    $response = shareClient('sender')->uploadMany('/api/transfer/send', 'files', [
        ['name' => 'note.txt', 'contents' => 'transfer me', 'type' => 'text/plain'],
    ]);

    assertSame(201, $response['status'], 'send failed: ' . substr($response['body'], 0, 200));

    $data = shareClient('sender')->json($response);
    assertTrue((bool)preg_match('/^\d{6}$/', (string)($data['code'] ?? '')), 'a six-digit code is expected');
    assertStringContains('/transfer/download/', (string)($data['download_url'] ?? ''));
    assertSame(1, $data['files_count'] ?? 0);
});

test('a script file cannot be sent', function (TestClient $_c): void {
    $response = shareClient('sender')->uploadMany('/api/transfer/send', 'files', [
        ['name' => 'shell.php', 'contents' => '<?php ?>', 'type' => 'text/plain'],
    ]);

    assertSame(422, $response['status']);
    assertStringContains('ไม่อนุญาต', $response['body']);
});

test('a transfer code is redeemable without signing in', function (TestClient $_c): void {
    $sent = shareClient('sender')->json(shareClient('sender')->uploadMany('/api/transfer/send', 'files', [
        ['name' => 'shared.txt', 'contents' => 'hello from the other side', 'type' => 'text/plain'],
    ]));

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
    shareClient('sender')->uploadMany('/api/transfer/send', 'files', [
        ['name' => 'mine.txt', 'contents' => 'x', 'type' => 'text/plain'],
    ]);

    $mine = shareClient('sender')->json(shareClient('sender')->get('/api/transfer'));
    $theirs = shareClient('other')->json(shareClient('other')->get('/api/transfer'));

    assertTrue(count($mine['transfers'] ?? []) > 0);
    assertSame([], $theirs['transfers'] ?? null, 'a different account starts with none');
});

test('another account cannot delete a transfer', function (TestClient $_c): void {
    $sent = shareClient('sender')->json(shareClient('sender')->uploadMany('/api/transfer/send', 'files', [
        ['name' => 'private.txt', 'contents' => 'x', 'type' => 'text/plain'],
    ]));

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

test('an unknown share token shows the expired page, not the account', function (TestClient $_c): void {
    $guest = new TestClient(TEST_BASE_URL);
    $response = $guest->get('/shared/' . str_repeat('f', 64));

    assertSame(410, $response['status'], 'a dead link is Gone, not a redirect into share mode');

    // And no share session was established on the way past.
    assertContains($guest->get('/tasks')['status'], [302, 403], 'the visitor must still have no access');
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
