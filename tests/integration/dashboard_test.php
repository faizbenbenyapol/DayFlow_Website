<?php
// =====================================================
// tests/integration/dashboard_test.php
//
// The summary endpoint builds one block of queries per widget, so it must
// return exactly the modules the client asked for — no more (wasted queries)
// and no fewer (blank widgets).
// =====================================================

declare(strict_types=1);

test('summary without a modules hint returns every module', function (TestClient $client): void {
    $data = $client->json($client->get('/api/dashboard/summary'));

    foreach (['tasks', 'calendar', 'finance', 'workout', 'subscriptions', 'projects', 'notes', 'stocks', 'transfer'] as $module) {
        assertArrayHasKey($module, $data, 'legacy callers must keep receiving every module');
    }
});

test('summary returns only the requested modules', function (TestClient $client): void {
    $data = $client->json($client->get('/api/dashboard/summary?modules=tasks,finance'));

    assertArrayHasKey('tasks', $data);
    assertArrayHasKey('finance', $data);
    assertArrayNotHasKey('stocks', $data, 'a hidden widget must not be built');
    assertArrayNotHasKey('projects', $data);
    assertSame(['tasks', 'finance'], $data['meta']['modules']);
});

test('summary ignores module names it does not know', function (TestClient $client): void {
    $data = $client->json($client->get('/api/dashboard/summary?modules=tasks,bogus,../etc/passwd'));

    assertSame(['tasks'], $data['meta']['modules'], 'unknown names must be dropped, not passed through');
    assertArrayHasKey('tasks', $data);
});

test('summary reports no failing modules on a healthy schema', function (TestClient $client): void {
    $data = $client->json($client->get('/api/dashboard/summary'));

    assertSame([], $data['meta']['warnings'], 'a module fell back to its empty value');
    assertFalse($data['meta']['partial']);
});

test('summary payload keeps the shape the widgets render', function (TestClient $client): void {
    $data = $client->json($client->get('/api/dashboard/summary?modules=tasks,finance'));

    assertArrayHasKey('overdue', $data['tasks']);
    assertArrayHasKey('items', $data['tasks']);
    foreach (['month', 'income', 'expense', 'balance'] as $key) {
        assertArrayHasKey($key, $data['finance']);
    }
});

test('summary requires a session', function (TestClient $_client): void {
    $anonymous = new TestClient(TEST_BASE_URL);
    $response  = $anonymous->get('/api/dashboard/summary');

    assertContains($response['status'], [401, 302], 'an unauthenticated caller must not get data');
});
