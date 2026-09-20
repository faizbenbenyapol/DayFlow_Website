<?php
// =====================================================
// tests/integration/search_test.php
// =====================================================

declare(strict_types=1);

/**
 * Seeds one searchable row per module the suite checks. Runs once; the runner
 * shares a single logged-in client across the cases in a suite.
 */
function seedSearchFixtures(TestClient $client): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $client->post('/api/tasks', ['title' => 'zebrafixture planning', 'description' => 'งานทดสอบ', 'quadrant' => 1]);
    $client->post('/api/bookmarks', ['title' => 'zebrafixture board', 'url' => 'https://example.test/zebra', 'category' => 'งาน']);
    $client->post('/api/quick-items', ['content' => 'อย่าลืม zebrafixture demo']);

    // A plain note whose body — not its title — holds the term.
    $note = $client->json($client->post('/api/notes', ['title' => 'บันทึกทั่วไป', 'is_encrypted' => false]));
    if (!empty($note['note']['id'])) {
        $client->post('/api/notes/' . (int)$note['note']['id'] . '/blocks', [
            'type' => 'text', 'content' => 'รายละเอียด zebrafixture ภายในเนื้อโน้ต',
        ]);
    }

    // An encrypted note whose body also holds the term. Its blocks are stored
    // as ciphertext and gated by a password, so search must never surface it.
    $secret = $client->json($client->post('/api/notes', [
        'title' => 'โน้ตลับ', 'is_encrypted' => true, 'password' => 'SecretPass123',
    ]));
    if (!empty($secret['note']['id'])) {
        $client->post('/api/notes/' . (int)$secret['note']['id'] . '/blocks', [
            'type' => 'text', 'content' => 'zebrafixture ที่ต้องไม่โผล่ในการค้นหา', 'password' => 'SecretPass123',
        ]);
    }
}

test('a search costs one request and spans several modules', function (TestClient $client): void {
    seedSearchFixtures($client);

    $data  = $client->json($client->get('/api/search?q=zebrafixture'));
    $types = array_unique(array_column($data['results'], 'type'));

    assertTrue(count($data['results']) > 0, 'seeded rows should be findable');
    assertTrue(count($types) > 1, 'search must cover more than one module, got ' . describeValue($types));
});

test('search finds text inside a note body, not just its title', function (TestClient $client): void {
    seedSearchFixtures($client);

    $data  = $client->json($client->get('/api/search?q=zebrafixture'));
    $notes = array_filter($data['results'], static fn(array $r): bool => str_contains($r['url'], '/notes'));

    assertTrue($notes !== [], 'a note matching only in its body must still be found');
});

test('search never surfaces the body of an encrypted note', function (TestClient $client): void {
    seedSearchFixtures($client);

    $data = $client->json($client->get('/api/search?q=zebrafixture'));
    foreach ($data['results'] as $result) {
        assertTrue(
            !str_contains($result['title'], 'โน้ตลับ'),
            'encrypted note leaked into search results'
        );
        assertTrue(
            !str_contains($result['subtitle'], 'ต้องไม่โผล่'),
            'encrypted note body leaked into search results'
        );
    }
});

test('the closest title match is ranked first', function (TestClient $client): void {
    seedSearchFixtures($client);

    $results = $client->json($client->get('/api/search?q=zebrafixture'))['results'];
    assertTrue($results !== [], 'expected at least one result');

    $first = mb_strtolower($results[0]['title']);
    assertTrue(
        str_starts_with($first, 'zebrafixture'),
        'a title starting with the query should outrank a body match, got ' . describeValue($results[0]['title'])
    );
});

test('search ignores queries shorter than two characters', function (TestClient $client): void {
    assertSame([], $client->json($client->get('/api/search?q=z'))['results']);
});

test('search results are capped', function (TestClient $client): void {
    $results = $client->json($client->get('/api/search?q=a'))['results'] ?? [];
    assertTrue(count($results) <= 20, 'the endpoint promises at most 20 results');
});

test('search requires a session', function (TestClient $_client): void {
    $anonymous = new TestClient(TEST_BASE_URL);
    assertContains($anonymous->get('/api/search?q=zebrafixture')['status'], [401, 302]);
});
