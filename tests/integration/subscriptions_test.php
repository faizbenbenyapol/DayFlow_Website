<?php
// =====================================================
// tests/integration/subscriptions_test.php
//
// Bills and reminders. Renewal is date arithmetic on a stored due date, which
// is where month-end bugs live.
// =====================================================

declare(strict_types=1);

function subsClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('sub_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $clients[$label] = $client;
}

function addSub(TestClient $client, string $cycle, string $due, array $extra = []): array
{
    $response = $client->post('/api/subscriptions', $extra + [
        'name' => 'Netflix', 'amount' => 199, 'billing_cycle' => $cycle, 'next_due_date' => $due, 'alert_days' => 3,
    ]);
    if ($response['status'] !== 201) {
        throw new RuntimeException("subscription rejected ({$response['status']}): " . $response['body']);
    }
    return $client->json($response)['subscription'];
}

function dueDateOf(TestClient $client, int $id): string
{
    foreach ($client->json($client->get('/api/subscriptions'))['subscriptions'] as $sub) {
        if ((int)$sub['id'] === $id) return $sub['next_due_date'];
    }
    throw new RuntimeException('subscription ' . $id . ' not listed');
}

test('a subscription is created, edited and deleted', function (TestClient $_c): void {
    $client = subsClient('crud');
    $sub = addSub($client, 'monthly', '2026-05-10');
    assertSame('Netflix', $sub['name']);

    $edit = $client->request('PUT', '/api/subscriptions/' . $sub['id'], [
        'name' => 'Spotify', 'amount' => 129, 'billing_cycle' => 'yearly', 'next_due_date' => '2026-06-01', 'alert_days' => 7,
    ]);
    assertSame(200, $edit['status']);
    assertSame('2026-06-01', dueDateOf($client, (int)$sub['id']));

    assertSame(200, $client->request('DELETE', '/api/subscriptions/' . $sub['id'], [])['status']);
    assertSame(404, $client->request('DELETE', '/api/subscriptions/' . $sub['id'], [])['status']);
});

test('every field is checked', function (TestClient $_c): void {
    $client = subsClient('validate');
    $base = ['name' => 'Gym', 'amount' => 500, 'billing_cycle' => 'monthly', 'next_due_date' => '2026-05-10', 'alert_days' => 3];

    $cases = [
        'no name'            => ['name' => ''],
        'name over the column' => ['name' => str_repeat('ก', 151)],
        'bad date'           => ['next_due_date' => '2026-02-30'],
        'wrong date format'  => ['next_due_date' => '10-05-2026'],
        'negative amount'    => ['amount' => -1],
        'absurd amount'      => ['amount' => 1000000000],
        'unknown cycle'      => ['billing_cycle' => 'daily'],
        'negative alert'     => ['alert_days' => -1],
        'alert too far out'  => ['alert_days' => 366],
    ];

    foreach ($cases as $label => $override) {
        assertSame(422, $client->post('/api/subscriptions', $override + $base)['status'], $label . ' should be refused');
    }
    assertSame(0, count($client->json($client->get('/api/subscriptions'))['subscriptions']));
});

test('a name at the column limit is accepted', function (TestClient $_c): void {
    $client = subsClient('longname');
    $name = str_repeat('ก', 150);
    assertSame($name, addSub($client, 'monthly', '2026-05-10', ['name' => $name])['name']);
});

test('renewing moves the due date by exactly one cycle', function (TestClient $_c): void {
    $client = subsClient('renew');

    $weekly  = addSub($client, 'weekly',  '2026-05-10');
    $monthly = addSub($client, 'monthly', '2026-05-10');
    $yearly  = addSub($client, 'yearly',  '2026-05-10');

    foreach ([$weekly, $monthly, $yearly] as $sub) {
        assertSame(200, $client->post('/api/subscriptions/' . $sub['id'] . '/renew', [])['status']);
    }

    assertSame('2026-05-17', dueDateOf($client, (int)$weekly['id']));
    assertSame('2026-06-10', dueDateOf($client, (int)$monthly['id']));
    assertSame('2027-05-10', dueDateOf($client, (int)$yearly['id']));
});

test('a bill due at the end of a month stays in the next month', function (TestClient $_c): void {
    $client = subsClient('monthend');

    $jan31 = addSub($client, 'monthly', '2026-01-31');
    $client->post('/api/subscriptions/' . $jan31['id'] . '/renew', []);
    assertSame('2026-02-28', dueDateOf($client, (int)$jan31['id']), '31 Jan + 1 month is the end of February, not 3 March');

    $leap = addSub($client, 'yearly', '2028-02-29');
    $client->post('/api/subscriptions/' . $leap['id'] . '/renew', []);
    assertSame('2029-02-28', dueDateOf($client, (int)$leap['id']), '29 Feb + 1 year is 28 Feb, not 1 March');

    $dec = addSub($client, 'monthly', '2026-12-15');
    $client->post('/api/subscriptions/' . $dec['id'] . '/renew', []);
    assertSame('2027-01-15', dueDateOf($client, (int)$dec['id']), 'across a year boundary');
});

test('a one-off bill cannot be renewed and says why', function (TestClient $_c): void {
    $client = subsClient('onetime');
    $sub = addSub($client, 'one_time', '2026-05-10');

    $response = $client->post('/api/subscriptions/' . $sub['id'] . '/renew', []);
    assertSame(400, $response['status']);
    assertSame('2026-05-10', dueDateOf($client, (int)$sub['id']), 'the date must not move');
});

test('one account cannot see, change, renew or delete another account\'s subscriptions', function (TestClient $_c): void {
    $owner = subsClient('owner');
    $other = subsClient('other');
    $sub   = addSub($owner, 'monthly', '2026-05-10');

    assertSame(0, count($other->json($other->get('/api/subscriptions'))['subscriptions']), 'not listed');
    assertSame(404, $other->request('PUT', '/api/subscriptions/' . $sub['id'], [
        'name' => 'x', 'billing_cycle' => 'monthly', 'next_due_date' => '2026-05-10',
    ])['status'], 'edit');
    assertSame(404, $other->post('/api/subscriptions/' . $sub['id'] . '/renew', [])['status'], 'renew');
    assertSame(404, $other->request('DELETE', '/api/subscriptions/' . $sub['id'], [])['status'], 'delete');
    assertSame('2026-05-10', dueDateOf($owner, (int)$sub['id']), 'the owner\'s date is untouched');
});
