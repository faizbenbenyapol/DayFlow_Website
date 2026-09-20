<?php
// =====================================================
// tests/integration/push_test.php — Web Push subscription management
//
// Actually delivering a push needs a real browser and a real push service, so
// these cover everything up to that point: configuration reporting, storing
// and removing a subscription, and the digest the service worker fetches.
// =====================================================

declare(strict_types=1);

/** A subscription shaped the way PushManager.subscribe().toJSON() produces. */
function fakeSubscription(string $suffix): array
{
    return [
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/test-' . TEST_RUN_ID . '-' . $suffix,
        'keys'     => [
            'p256dh' => 'BExampleClientPublicKeyValueThatIsLongEnoughForATest',
            'auth'   => 'ExampleAuthSecret123',
        ],
    ];
}

test('the config endpoint reports whether push is usable', function (TestClient $client): void {
    $config = $client->json($client->get('/api/push/config'));

    assertArrayHasKey('enabled', $config);
    assertArrayHasKey('devices', $config);

    if ($config['enabled']) {
        // The applicationServerKey the browser needs: an uncompressed P-256
        // point, base64url encoded.
        assertTrue((bool)preg_match('/^[A-Za-z0-9_-]{80,}$/', (string)$config['public_key']), 'not a VAPID public key');
    } else {
        assertSame(null, $config['public_key'], 'no key should be exposed when push is off');
    }
});

test('subscribing stores the browser and unsubscribing removes it', function (TestClient $client): void {
    $config = $client->json($client->get('/api/push/config'));
    if (!$config['enabled']) return; // nothing to store without VAPID keys

    $before = $config['devices'];
    $subscription = fakeSubscription('roundtrip');

    $added = $client->json($client->post('/api/push/subscribe', $subscription));
    assertSame($before + 1, $added['devices'] ?? -1, 'subscribing should register one device');

    $removed = $client->json($client->post('/api/push/unsubscribe', ['endpoint' => $subscription['endpoint']]));
    assertSame($before, $removed['devices'] ?? -1, 'unsubscribing should give the count back');
});

test('re-subscribing the same browser does not add a second row', function (TestClient $client): void {
    $config = $client->json($client->get('/api/push/config'));
    if (!$config['enabled']) return;

    $subscription = fakeSubscription('duplicate');

    $first  = $client->json($client->post('/api/push/subscribe', $subscription));
    $second = $client->json($client->post('/api/push/subscribe', $subscription));

    assertSame($first['devices'], $second['devices'], 'the same endpoint must update in place');

    $client->post('/api/push/unsubscribe', ['endpoint' => $subscription['endpoint']]);
});

test('an incomplete or insecure subscription is rejected', function (TestClient $client): void {
    $config = $client->json($client->get('/api/push/config'));
    if (!$config['enabled']) return;

    assertSame(422, $client->post('/api/push/subscribe', ['endpoint' => 'https://example.test/x'])['status'],
        'keys are required');

    assertSame(422, $client->post('/api/push/subscribe', [
        'endpoint' => 'http://insecure.example/push',
        'keys'     => ['p256dh' => 'x', 'auth' => 'y'],
    ])['status'], 'a push endpoint must be https');

    assertSame(422, $client->post('/api/push/subscribe', [
        'endpoint' => 'not-a-url',
        'keys'     => ['p256dh' => 'x', 'auth' => 'y'],
    ])['status']);
});

test('the pending digest is shaped for the service worker', function (TestClient $client): void {
    $data = $client->json($client->get('/api/push/pending'));

    assertArrayHasKey('items', $data);
    foreach ($data['items'] as $item) {
        foreach (['title', 'body', 'url', 'tag'] as $key) {
            assertArrayHasKey($key, $item);
        }
        assertTrue($item['title'] !== '', 'a notification needs a title');
    }
});

test('a task due today shows up in the digest', function (TestClient $client): void {
    $title = 'งานเตือนวันนี้ ' . TEST_RUN_ID;
    $client->post('/api/tasks', [
        'title'    => $title,
        'quadrant' => 1,
        'due_date' => date('Y-m-d'),
    ]);

    $items = $client->json($client->get('/api/push/pending'))['items'];
    $dueToday = array_filter($items, static fn(array $i): bool => $i['tag'] === 'tasks-today');

    assertTrue($dueToday !== [], 'a task due today should produce a notification');
});

test('a test push without a registered browser is refused', function (TestClient $_client): void {
    // A fresh account has no subscriptions, so the endpoint should say so
    // rather than silently succeeding.
    $client = new TestClient(TEST_BASE_URL);
    $client->login('push_none_' . TEST_RUN_ID, 'TestPass123!');

    $response = $client->post('/api/push/test', []);
    assertContains($response['status'], [422, 503], 'expected a clear refusal, got ' . $response['status']);
});

test('push endpoints require a session', function (TestClient $_client): void {
    $anonymous = new TestClient(TEST_BASE_URL);

    assertContains($anonymous->get('/api/push/config')['status'], [401, 302]);
    assertContains($anonymous->get('/api/push/pending')['status'], [401, 302]);
});
