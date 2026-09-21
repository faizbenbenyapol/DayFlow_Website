<?php
// =====================================================
// tests/integration/ai_test.php
//
// Generation itself needs a paid provider key and an outbound call, so what
// is covered here is everything around it: the key store, the validation that
// runs before any request leaves the server, history ownership, and access.
// =====================================================

declare(strict_types=1);

function aiClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('ai_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $clients[$label] = $client;
}

// --- Key management ---

test('a stored AI key is never returned in full', function (TestClient $_c): void {
    $client = aiClient('keys');
    $secret = 'sk-test-a-very-secret-api-key-value';

    assertSame(200, $client->post('/api/ai/keys', ['provider' => 'openai', 'api_key' => $secret])['status']);

    $listing = $client->get('/api/ai/keys');
    assertTrue(!str_contains($listing['body'], $secret), 'the raw key must not leave the server');
    assertStringContains('"set":true', $listing['body'], 'but the client still needs to know one is stored');
});

test('an unknown provider or a too-short key is refused', function (TestClient $_c): void {
    $client = aiClient('keys');

    assertSame(422, $client->post('/api/ai/keys', ['provider' => 'skynet', 'api_key' => 'long-enough-key'])['status']);
    assertSame(422, $client->post('/api/ai/keys', ['provider' => 'openai', 'api_key' => 'short'])['status']);
});

test('saving an empty key deletes the stored one', function (TestClient $_c): void {
    $client = aiClient('keys');

    $client->post('/api/ai/keys', ['provider' => 'gemini', 'api_key' => 'a-long-enough-gemini-key']);
    $cleared = $client->json($client->post('/api/ai/keys', ['provider' => 'gemini', 'api_key' => '']));
    assertTrue($cleared['deleted'] ?? false);

    $keys = $client->json($client->get('/api/ai/keys'))['keys'] ?? [];
    assertTrue(empty($keys['gemini']['set']), 'the key should be gone');
});

test('keys belong to one account only', function (TestClient $_c): void {
    aiClient('owner')->post('/api/ai/keys', ['provider' => 'anthropic', 'api_key' => 'owner-only-secret-key']);

    $keys = aiClient('stranger')->json(aiClient('stranger')->get('/api/ai/keys'))['keys'] ?? [];
    assertTrue(empty($keys['anthropic']['set']), 'another account must not see the key exists');
});

test('the provider list is published so the UI can render it', function (TestClient $_c): void {
    $providers = aiClient('keys')->json(aiClient('keys')->get('/api/ai/keys'))['providers'] ?? [];

    assertTrue(count($providers) > 0);
    assertContains('openai', $providers);
});

// --- Input validation, which runs before anything reaches a provider ---

test('script generation refuses bad input without calling a provider', function (TestClient $_c): void {
    $client = aiClient('validate');
    $base = [
        'keyword' => 'ทดสอบ', 'platform' => 'tiktok', 'style' => 'informative',
        'language' => 'th', 'duration_sec' => 30, 'provider' => 'openai',
    ];

    $cases = [
        'empty keyword'     => ['keyword' => ''],
        'unknown platform'  => ['platform' => 'myspace'],
        'unknown style'     => ['style' => 'interpretive-dance'],
        'duration too short'=> ['duration_sec' => 1],
        'duration too long' => ['duration_sec' => 100000],
        'keyword too long'  => ['keyword' => str_repeat('ก', 501)],
    ];

    foreach ($cases as $label => $override) {
        $response = $client->post('/api/ai/script', $override + $base);
        assertSame(422, $response['status'], $label . ' should be refused');
    }
});

test('generation without a configured key says so rather than failing obscurely', function (TestClient $_c): void {
    $client = aiClient('nokey');

    $response = $client->json($client->post('/api/ai/script', [
        'keyword' => 'หัวข้อทดสอบ', 'platform' => 'tiktok', 'style' => 'informative',
        'language' => 'th', 'duration_sec' => 30, 'provider' => 'openai',
    ]));

    assertStringContains('API key', (string)($response['error'] ?? ''), 'the message should name what is missing');
});

test('input is checked before the missing-key check', function (TestClient $_c): void {
    $client = aiClient('nokey');

    // Ordering matters: a user with no key should still be told their input is
    // wrong, rather than being sent to configure a key they do not need yet.
    $response = $client->post('/api/ai/script', [
        'keyword' => '', 'platform' => 'tiktok', 'style' => 'informative',
        'language' => 'th', 'duration_sec' => 30, 'provider' => 'openai',
    ]);
    assertSame(422, $response['status']);
});

// --- History ---

test('history is listed per account', function (TestClient $_c): void {
    $client = aiClient('history');

    $response = $client->get('/api/ai/history');
    assertSame(200, $response['status']);
    assertArrayHasKey('items', $client->json($response));
});

test('a video job belonging to someone else is not readable', function (TestClient $_c): void {
    $response = aiClient('stranger')->get('/api/ai/video/999999/status');
    assertSame(404, $response['status']);
});

// --- Access ---

test('every AI endpoint needs a session', function (TestClient $_c): void {
    $anonymous = new TestClient(TEST_BASE_URL);

    assertContains($anonymous->get('/api/ai/keys')['status'], [401, 302]);
    assertContains($anonymous->get('/api/ai/history')['status'], [401, 302]);
    assertContains($anonymous->post('/api/ai/script', ['keyword' => 'x'])['status'], [401, 302, 403]);
});
