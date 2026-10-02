<?php
// =====================================================
// tests/integration/finance_test.php
//
// Money module. The summary and chart sums are what the user reads as "how
// much did I spend", and a transaction or category must never be reachable
// from another account.
// =====================================================

declare(strict_types=1);

/** An account with no transactions, so the sums below start from zero. */
function financeClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('fin_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $clients[$label] = $client;
}

/** Records a transaction and returns it as the API echoes it back. */
function addTxn(TestClient $client, string $type, float $amount, string $date, array $extra = []): array
{
    $response = $client->post('/api/finance', $extra + [
        'type' => $type, 'amount' => $amount, 'txn_date' => $date, 'description' => 'test',
    ]);
    if ($response['status'] !== 201) {
        throw new RuntimeException("transaction rejected ({$response['status']}): " . $response['body']);
    }
    return $client->json($response)['transaction'];
}

// --- Create, read, update, delete ---

test('a transaction is recorded and listed back', function (TestClient $_c): void {
    $client = financeClient('crud');
    $txn = addTxn($client, 'expense', 120.5, '2026-03-10', ['description' => 'กาแฟ <b>']);

    assertSame('expense', $txn['type']);
    assertSame(120.5, (float)$txn['amount']);

    $list = $client->json($client->get('/api/finance?month=2026-03'))['transactions'];
    assertSame(1, count($list));
    assertSame('กาแฟ <b>', $list[0]['description'], 'text is stored as typed and escaped on display');
});

test('a transaction can be edited and deleted', function (TestClient $_c): void {
    $client = financeClient('edit');
    $txn = addTxn($client, 'expense', 10, '2026-03-01');

    $edit = $client->request('PUT', '/api/finance/' . $txn['id'], [
        'type' => 'income', 'amount' => 99, 'txn_date' => '2026-03-02', 'description' => 'แก้แล้ว',
    ]);
    assertSame(200, $edit['status']);

    $list = $client->json($client->get('/api/finance?month=2026-03'))['transactions'];
    assertSame('income', $list[0]['type']);
    assertSame(99.0, (float)$list[0]['amount']);

    assertSame(200, $client->request('DELETE', '/api/finance/' . $txn['id'], [])['status']);
    assertSame(0, count($client->json($client->get('/api/finance?month=2026-03'))['transactions']));
    assertSame(404, $client->request('DELETE', '/api/finance/' . $txn['id'], [])['status'], 'already gone');
});

test('amount, type and date are all checked', function (TestClient $_c): void {
    $client = financeClient('validate');
    $base = ['type' => 'expense', 'amount' => 50, 'txn_date' => '2026-03-10'];

    $cases = [
        'zero amount'       => ['amount' => 0],
        'negative amount'   => ['amount' => -5],
        'text amount'       => ['amount' => 'lots'],
        'absurd amount'     => ['amount' => 1000000000],
        'unknown type'      => ['type' => 'gift'],
        'missing type'      => ['type' => ''],
        'not a date'        => ['txn_date' => 'tomorrow'],
        'impossible date'   => ['txn_date' => '2026-02-30'],
        'wrong date format' => ['txn_date' => '10/03/2026'],
    ];

    foreach ($cases as $label => $override) {
        assertSame(422, $client->post('/api/finance', $override + $base)['status'], $label . ' should be refused');
    }
    assertSame(0, count($client->json($client->get('/api/finance'))['transactions']), 'nothing may be stored by a refused request');
});

// --- Sums ---

test('the monthly summary adds income and expense separately', function (TestClient $_c): void {
    $client = financeClient('summary');
    addTxn($client, 'income', 1000, '2026-04-01');
    addTxn($client, 'income', 250.25, '2026-04-30');
    addTxn($client, 'expense', 400, '2026-04-15');
    addTxn($client, 'expense', 999, '2026-05-01');   // next month
    addTxn($client, 'expense', 999, '2026-03-31');   // previous month

    $summary = $client->json($client->get('/api/finance/summary?month=2026-04'));
    assertSame(1250.25, (float)$summary['income']);
    assertSame(400.0, (float)$summary['expense']);
    assertSame(850.25, (float)$summary['balance']);
});

test('the summary says where the month\'s expenses went, over the whole month', function (TestClient $_c): void {
    $client = financeClient('bycat');
    $food = $client->json($client->post('/api/finance/categories', ['name' => 'อาหาร ' . TEST_RUN_ID, 'type' => 'expense']));
    $fare = $client->json($client->post('/api/finance/categories', ['name' => 'เดินทาง ' . TEST_RUN_ID, 'type' => 'expense']));
    $foodId = (int)($food['id'] ?? $food['category']['id'] ?? 0);
    $fareId = (int)($fare['id'] ?? $fare['category']['id'] ?? 0);
    assertTrue($foodId > 0 && $fareId > 0, 'the categories were created');

    addTxn($client, 'expense', 120, '2026-09-02', ['category_id' => $foodId]);
    addTxn($client, 'expense', 80, '2026-09-03', ['category_id' => $foodId]);
    addTxn($client, 'expense', 500, '2026-09-04', ['category_id' => $fareId]);
    addTxn($client, 'expense', 30, '2026-09-05');                         // no category
    addTxn($client, 'income', 9999, '2026-09-06', ['category_id' => $foodId]); // income is not an expense
    addTxn($client, 'expense', 700, '2026-10-01', ['category_id' => $fareId]); // another month

    $summary = $client->json($client->get('/api/finance/summary?month=2026-09'));
    $rows = $summary['by_category'];

    assertSame(3, count($rows), 'two categories and the uncategorised');
    assertSame(500.0, (float)$rows[0]['amount'], 'biggest first');
    assertSame(200.0, (float)$rows[1]['amount'], 'a category adds its rows together');
    assertSame(30.0, (float)$rows[2]['amount']);
    assertSame('ไม่ระบุหมวด', $rows[2]['name'], 'no category has a name of its own');
    assertSame(730.0, (float)array_sum(array_column($rows, 'amount')), 'and the parts make the month\'s expense');
    assertSame(730.0, (float)$summary['expense']);
});

test('the first and last day of a month both count, the neighbours do not', function (TestClient $_c): void {
    $client = financeClient('bounds');
    addTxn($client, 'expense', 1, '2026-02-01');
    addTxn($client, 'expense', 2, '2026-02-28');
    addTxn($client, 'expense', 4, '2026-01-31');
    addTxn($client, 'expense', 8, '2026-03-01');

    assertSame(3.0, (float)$client->json($client->get('/api/finance/summary?month=2026-02'))['expense']);
    assertSame(2, count($client->json($client->get('/api/finance?month=2026-02'))['transactions']));
});

test('the yearly chart always has twelve months and puts money in the right one', function (TestClient $_c): void {
    $client = financeClient('chart');
    addTxn($client, 'income', 500, '2025-01-05');
    addTxn($client, 'expense', 70, '2025-12-31');
    addTxn($client, 'expense', 999, '2026-01-01');   // another year

    $chart = $client->json($client->get('/api/finance/chart?year=2025'))['chart'];
    assertSame(12, count($chart));
    assertSame(500.0, (float)$chart[0]['income']);
    assertSame(70.0, (float)$chart[11]['expense']);
    assertSame(0.0, (float)$chart[5]['expense'], 'a month with no data is zero, not missing');
    assertSame(0.0, (float)$chart[0]['expense'], 'the next year must not leak into January');
});

test('a malformed month falls back to the current month instead of failing', function (TestClient $_c): void {
    $client = financeClient('badmonth');
    foreach (['2026-13', 'abc', "2026-01'; DROP TABLE finances;--"] as $bad) {
        assertSame(200, $client->get('/api/finance/summary?month=' . rawurlencode($bad))['status'], $bad);
    }
});

test('the list can be filtered by type and by date range', function (TestClient $_c): void {
    $client = financeClient('filters');
    addTxn($client, 'income', 10, '2026-06-01');
    addTxn($client, 'expense', 20, '2026-06-10');
    addTxn($client, 'expense', 30, '2026-06-20');

    $expenses = $client->json($client->get('/api/finance?type=expense'))['transactions'];
    assertSame(2, count($expenses));

    $range = $client->json($client->get('/api/finance?start_date=2026-06-05&end_date=2026-06-15'))['transactions'];
    assertSame(1, count($range));
    assertSame(20.0, (float)$range[0]['amount']);

    $newestFirst = array_column($client->json($client->get('/api/finance'))['transactions'], 'txn_date');
    assertSame(['2026-06-20', '2026-06-10', '2026-06-01'], $newestFirst);
});

// --- Categories ---

test('a category is created, renamed and attached to a transaction', function (TestClient $_c): void {
    $client = financeClient('cats');
    $created = $client->post('/api/finance/categories', ['name' => 'อาหาร', 'type' => 'expense']);
    assertSame(201, $created['status']);
    $catId = (int)$client->json($created)['id'];
    assertTrue($catId > 0, 'the new id is returned');

    $txn = addTxn($client, 'expense', 60, '2026-07-01', ['category_id' => $catId]);
    $row = $client->json($client->get('/api/finance?month=2026-07'))['transactions'][0];
    assertSame('อาหาร', $row['category_name']);

    $rename = $client->request('PUT', '/api/finance/categories/' . $catId, ['name' => 'ของกิน', 'type' => 'expense']);
    assertSame(200, $rename['status']);
    assertSame('ของกิน', $client->json($client->get('/api/finance?month=2026-07'))['transactions'][0]['category_name']);
    assertTrue($txn['id'] > 0);
});

test('deleting a category keeps its transactions', function (TestClient $_c): void {
    $client = financeClient('catdelete');
    $catId = (int)$client->json($client->post('/api/finance/categories', ['name' => 'ชั่วคราว', 'type' => 'expense']))['id'];
    addTxn($client, 'expense', 15, '2026-07-02', ['category_id' => $catId]);

    assertSame(200, $client->request('DELETE', '/api/finance/categories/' . $catId, [])['status']);

    $list = $client->json($client->get('/api/finance?month=2026-07'))['transactions'];
    assertSame(1, count($list), 'the money must survive its category');
    assertSame(null, $list[0]['category_name']);
});

test('a category needs a sensible name and type', function (TestClient $_c): void {
    $client = financeClient('catvalidate');

    assertSame(422, $client->post('/api/finance/categories', ['name' => '', 'type' => 'expense'])['status'], 'empty');
    assertSame(422, $client->post('/api/finance/categories', ['name' => '   ', 'type' => 'expense'])['status'], 'blank');
    assertSame(422, $client->post('/api/finance/categories', ['name' => str_repeat('ก', 101), 'type' => 'expense'])['status'], 'too long');
    assertSame(422, $client->post('/api/finance/categories', ['name' => 'ok', 'type' => 'savings'])['status'], 'unknown type');
});

// --- Isolation between accounts ---

test('one account cannot read, edit or delete another account\'s transactions', function (TestClient $_c): void {
    $owner  = financeClient('owner');
    $thief  = financeClient('thief');
    $txn    = addTxn($owner, 'expense', 321, '2026-08-01');

    assertSame(0, count($thief->json($thief->get('/api/finance?month=2026-08'))['transactions']), 'not in the list');
    assertSame(0.0, (float)$thief->json($thief->get('/api/finance/summary?month=2026-08'))['expense'], 'not in the sums');

    $edit = $thief->request('PUT', '/api/finance/' . $txn['id'], ['type' => 'income', 'amount' => 1, 'txn_date' => '2026-08-01']);
    assertSame(404, $edit['status'], 'edit');
    assertSame(404, $thief->request('DELETE', '/api/finance/' . $txn['id'], [])['status'], 'delete');

    $still = $owner->json($owner->get('/api/finance?month=2026-08'))['transactions'];
    assertSame(321.0, (float)$still[0]['amount'], 'the owner\'s record is untouched');
    assertSame('expense', $still[0]['type']);
});

test('one account cannot use or change another account\'s categories', function (TestClient $_c): void {
    $owner = financeClient('catowner');
    $other = financeClient('catother');
    $catId = (int)$owner->json($owner->post('/api/finance/categories', ['name' => 'ลับเฉพาะ', 'type' => 'expense']))['id'];

    assertSame(404, $other->request('PUT', '/api/finance/categories/' . $catId, ['name' => 'ยึด', 'type' => 'income'])['status'], 'rename');
    assertSame(404, $other->request('DELETE', '/api/finance/categories/' . $catId, [])['status'], 'delete');

    $names = array_column($other->json($other->get('/api/finance/categories'))['categories'], 'name');
    assertNotContains('ลับเฉพาะ', $names, 'not listed');

    // Pointing a transaction at somebody else's category would reveal its name.
    $payload = ['type' => 'expense', 'amount' => 5, 'txn_date' => '2026-08-05', 'category_id' => $catId];
    assertSame(422, $other->post('/api/finance', $payload)['status'], 'create');

    $mine = addTxn($other, 'expense', 5, '2026-08-05');
    assertSame(422, $other->request('PUT', '/api/finance/' . $mine['id'], $payload)['status'], 'edit');

    $row = $other->json($other->get('/api/finance?month=2026-08'))['transactions'][0];
    assertSame(null, $row['category_name'], 'a foreign category name must never be shown');
});

test('finance endpoints require a session', function (TestClient $_c): void {
    $anonymous = new TestClient(TEST_BASE_URL);
    foreach (['/api/finance', '/api/finance/summary', '/api/finance/chart', '/api/finance/categories'] as $path) {
        assertTrue(in_array($anonymous->get($path)['status'], [401, 302], true), $path . ' must not answer anonymously');
    }
});
