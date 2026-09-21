<?php
// =====================================================
// tests/integration/not_found_test.php
//
// A delete or an edit aimed at a record that is not yours must say so. Every
// model here already scopes its SQL by user_id, so nothing was ever at risk —
// but several endpoints reported success regardless, which left the interface
// showing a row as deleted until the next reload, and hid the fact that
// somebody was addressing records they do not own.
//
// One case per endpoint, using an id that cannot exist.
// =====================================================

declare(strict_types=1);

const MISSING_ID = 999999;

function ownerClient(): TestClient
{
    static $client = null;
    if ($client !== null) return $client;

    $client = new TestClient(TEST_BASE_URL);
    $client->login('nf_owner_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
}

/** A second account, used to address the first account's records. */
function strangerClient(): TestClient
{
    static $client = null;
    if ($client !== null) return $client;

    $client = new TestClient(TEST_BASE_URL);
    $client->login('nf_stranger_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
}

test('deleting an AI history entry that does not exist is a 404', function (TestClient $_c): void {
    assertSame(404, ownerClient()->request('DELETE', '/api/ai/history/' . MISSING_ID, [])['status']);
});

test('deleting a finance category that does not exist is a 404', function (TestClient $_c): void {
    assertSame(404, ownerClient()->request('DELETE', '/api/finance/categories/' . MISSING_ID, [])['status']);
});

test('editing a finance category that does not exist is a 404', function (TestClient $_c): void {
    $response = ownerClient()->request('PUT', '/api/finance/categories/' . MISSING_ID, [
        'name' => 'หมวดใหม่', 'type' => 'expense',
    ]);
    assertSame(404, $response['status']);
});

test('deleting a note tag that does not exist is a 404', function (TestClient $_c): void {
    assertSame(404, ownerClient()->request('DELETE', '/api/notes/tags/' . MISSING_ID, [])['status']);
});

test('deleting a skill that does not exist is a 404', function (TestClient $_c): void {
    assertSame(404, ownerClient()->request('DELETE', '/api/skills/' . MISSING_ID, [])['status']);
});

test('editing a skill that does not exist is a 404', function (TestClient $_c): void {
    $response = ownerClient()->request('PUT', '/api/skills/' . MISSING_ID, [
        'name' => 'ทักษะใหม่', 'target_hours' => 100, 'color' => '#3b82f6',
    ]);
    assertSame(404, $response['status']);
});

test('deleting a skill log that does not exist is a 404', function (TestClient $_c): void {
    assertSame(404, ownerClient()->request('DELETE', '/api/skills/logs/' . MISSING_ID, [])['status']);
});

// --- The same records, addressed by somebody else ---

test('another account cannot delete a finance category', function (TestClient $_c): void {
    $owner = ownerClient();
    $created = $owner->json($owner->post('/api/finance/categories', [
        'name' => 'หมวดของเจ้าของ ' . TEST_RUN_ID, 'type' => 'expense',
    ]));
    $id = (int)($created['id'] ?? 0);
    assertTrue($id > 0, 'category not created: ' . json_encode($created, JSON_UNESCAPED_UNICODE));

    assertSame(404, strangerClient()->request('DELETE', "/api/finance/categories/{$id}", [])['status']);

    // And it is still there for its owner.
    $categories = $owner->json($owner->get('/api/finance/categories'))['categories'] ?? [];
    assertContains($id, array_map('intval', array_column($categories, 'id')));
});

test('another account cannot rename a finance category', function (TestClient $_c): void {
    $owner = ownerClient();
    $created = $owner->json($owner->post('/api/finance/categories', [
        'name' => 'หมวดห้ามแก้ ' . TEST_RUN_ID, 'type' => 'income',
    ]));
    $id = (int)($created['id'] ?? 0);

    assertSame(404, strangerClient()->request('PUT', "/api/finance/categories/{$id}", [
        'name' => 'ถูกแก้โดยคนอื่น', 'type' => 'income',
    ])['status']);

    $categories = $owner->json($owner->get('/api/finance/categories'))['categories'] ?? [];
    $mine = array_values(array_filter($categories, static fn(array $c): bool => (int)$c['id'] === $id));
    assertStringContains('หมวดห้ามแก้', $mine[0]['name'] ?? '', 'the name must be unchanged');
});

test('another account cannot delete a skill', function (TestClient $_c): void {
    $owner = ownerClient();
    $created = $owner->json($owner->post('/api/skills', [
        'name' => 'ทักษะของเจ้าของ ' . TEST_RUN_ID, 'target_hours' => 500, 'color' => '#3b82f6',
    ]));
    $id = $created['id'] ?? ($created['skill']['id'] ?? null);
    assertTrue($id !== null, 'skill not created: ' . json_encode($created, JSON_UNESCAPED_UNICODE));

    assertSame(404, strangerClient()->request('DELETE', '/api/skills/' . rawurlencode((string)$id), [])['status']);

    $skills = strangerClient()->json(strangerClient()->get('/api/skills'));
    assertTrue(is_array($skills), 'the stranger still gets their own (empty) list');
});
