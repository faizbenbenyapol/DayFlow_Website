<?php
// =====================================================
// tests/integration/text_fidelity_test.php
//
// Input is no longer run through strip_tags(): text is stored exactly as
// typed and escaped where it is shown. These cases pin both halves — nothing
// is lost on the way in, and nothing typed can become markup on the way out.
// =====================================================

declare(strict_types=1);

/** Text strip_tags() used to mangle: it cut everything from the first "<". */
const AWKWARD_TEXT = 'ถ้า a<b และ x>1 ก็ส่ง "ของ" <3 & อย่าลืม <ของขวัญ>';

function fidelityClient(): TestClient
{
    static $client = null;
    if ($client !== null) return $client;
    $client = new TestClient(TEST_BASE_URL);
    $client->login('fidelity_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
}

test('text with angle brackets is stored exactly as typed', function (TestClient $_c): void {
    $client = fidelityClient();

    $task = $client->json($client->post('/api/tasks', ['title' => AWKWARD_TEXT, 'quadrant' => 1]))['task'] ?? null;
    assertSame(AWKWARD_TEXT, $task['title'] ?? null, 'task title');

    $note = $client->json($client->post('/api/notes', ['title' => AWKWARD_TEXT, 'is_encrypted' => false]))['note'] ?? null;
    assertSame(AWKWARD_TEXT, $note['title'] ?? null, 'note title');

    $client->post('/api/quick-items', ['content' => AWKWARD_TEXT]);
    $items = $client->json($client->get('/api/quick-items'))['items'] ?? [];
    assertContains(AWKWARD_TEXT, array_column($items, 'content'), 'quick item');
});

test('a page never echoes stored text as markup', function (TestClient $_c): void {
    $client = fidelityClient();
    $payload = '</script><img src=x id=injected>';

    $note = $client->json($client->post('/api/notes', ['title' => $payload, 'is_encrypted' => false]))['note'];
    // Tag names travel in the JSON body straight to the model.
    $client->request('PUT', '/api/notes/' . (int)$note['id'], ['title' => $payload, 'tags' => [$payload]]);

    $page = $client->get('/notes/' . (int)$note['id']);
    assertSame(200, $page['status']);
    assertFalse(str_contains($page['body'], $payload), 'the raw payload must not appear anywhere in the page');
    assertFalse(str_contains($page['body'], '<img src=x'), 'the payload must not become a real tag');
    assertStringContains(trim(json_encode('</script>', JSON_HEX_TAG), '"'), $page['body'], 'the data blob carries it hex-escaped');
});

test('every app page loads the shared escaper before its own script', function (TestClient $_c): void {
    $client = fidelityClient();

    foreach (['/', '/tasks', '/notes', '/projects', '/skills', '/stocks', '/settings'] as $path) {
        $body = $client->get($path)['body'];
        $escaper = strpos($body, '/assets/js/html.js');
        assertTrue($escaper !== false, "{$path} must load html.js");

        // Inline page scripts and page bundles both come after it.
        foreach (['<script nonce=', '/assets/js/app.js'] as $marker) {
            $at = strpos($body, $marker, strpos($body, '</head>') ?: 0);
            if ($at !== false) assertTrue($escaper < $at, "{$path}: html.js must come before {$marker}");
        }
    }
});
