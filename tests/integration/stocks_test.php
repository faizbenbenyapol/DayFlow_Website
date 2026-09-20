<?php
// =====================================================
// tests/integration/stocks_test.php
//
// The largest file in the project and, until now, entirely untested. The
// portfolio arithmetic gets the most attention: it runs an average-cost basis
// with fees folded in, and a mistake there misreports somebody's money.
// =====================================================

declare(strict_types=1);

/** An account with an empty portfolio, so the sums below start from zero. */
function stocksClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('stk_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $clients[$label] = $client;
}

/** Records a trade and returns the created transaction. */
function trade(TestClient $client, string $ticker, string $side, float $qty, float $price, float $fee = 0.0, string $date = '2026-01-15'): array
{
    $response = $client->post('/api/stocks', [
        'ticker'   => $ticker,
        'market'   => 'US',
        'side'     => $side,
        'quantity' => $qty,
        'price'    => $price,
        'fee'      => $fee,
        'currency' => 'USD',
        'txn_date' => $date,
    ]);

    if ($response['status'] !== 201) {
        throw new RuntimeException("trade rejected ({$response['status']}): " . $response['body']);
    }
    return $client->json($response)['transaction'];
}

/** The holding for one ticker in the portfolio summary, or null. */
function holdingFor(TestClient $client, string $ticker): ?array
{
    $summary = $client->json($client->get('/api/stocks/summary'));
    foreach ($summary['holdings'] ?? [] as $holding) {
        if (($holding['ticker'] ?? '') === $ticker) return $holding;
    }
    return null;
}

// --- Transactions ---

test('a trade is recorded and listed back', function (TestClient $_c): void {
    $client = stocksClient('crud');
    $txn = trade($client, 'AAPL', 'buy', 10, 150.0, 1.5);

    assertSame('AAPL', $txn['ticker']);
    assertSame('buy', $txn['side']);

    $list = $client->json($client->get('/api/stocks'));
    $mine = array_filter($list['transactions'] ?? [], static fn(array $t): bool => (int)$t['id'] === (int)$txn['id']);
    assertSame(1, count($mine));
});

test('the ticker is normalised and validated', function (TestClient $_c): void {
    $client = stocksClient('validate');

    // Lower case is accepted and stored upper case.
    $txn = trade($client, 'msft', 'buy', 1, 100.0);
    assertSame('MSFT', $txn['ticker'], 'tickers are stored upper case');

    foreach (['', 'HAS SPACE', 'WAY-TOO-LONG-A-TICKER-NAME', 'ติ๊กเกอร์'] as $bad) {
        $response = $client->post('/api/stocks', [
            'ticker' => $bad, 'market' => 'US', 'side' => 'buy',
            'quantity' => 1, 'price' => 1, 'currency' => 'USD', 'txn_date' => '2026-01-15',
        ]);
        assertSame(422, $response['status'], "ticker '{$bad}' should be refused");
    }
});

test('quantity, price, fee, side, market and currency are all checked', function (TestClient $_c): void {
    $client = stocksClient('validate');
    $base = [
        'ticker' => 'AAPL', 'market' => 'US', 'side' => 'buy',
        'quantity' => 1, 'price' => 1, 'fee' => 0, 'currency' => 'USD', 'txn_date' => '2026-01-15',
    ];

    $cases = [
        'zero quantity'    => ['quantity' => 0],
        'negative quantity'=> ['quantity' => -5],
        'negative price'   => ['price' => -1],
        'negative fee'     => ['fee' => -1],
        'unknown side'     => ['side' => 'short'],
        'unknown market'   => ['market' => 'MARS'],
        'bad currency'     => ['currency' => 'DOLLARS'],
    ];

    foreach ($cases as $label => $override) {
        assertSame(422, $client->post('/api/stocks', $override + $base)['status'], $label . ' should be refused');
    }
});

test('a trade belonging to someone else cannot be touched', function (TestClient $_c): void {
    $mine = trade(stocksClient('owner'), 'NVDA', 'buy', 5, 400.0);
    $other = stocksClient('intruder');

    assertSame(404, $other->request('PUT', '/api/stocks/' . (int)$mine['id'], [
        'ticker' => 'NVDA', 'market' => 'US', 'side' => 'buy',
        'quantity' => 999, 'price' => 1, 'currency' => 'USD', 'txn_date' => '2026-01-15',
    ])['status']);

    assertSame(404, $other->request('DELETE', '/api/stocks/' . (int)$mine['id'], [])['status']);

    // And it is still there, untouched.
    $list = stocksClient('owner')->json(stocksClient('owner')->get('/api/stocks'));
    $still = array_filter($list['transactions'] ?? [], static fn(array $t): bool => (int)$t['id'] === (int)$mine['id']);
    assertSame(1, count($still));
});

// --- Portfolio arithmetic ---

test('a single buy becomes a holding at cost plus fee', function (TestClient $_c): void {
    $client = stocksClient('single');
    trade($client, 'AAPL', 'buy', 10, 100.0, 5.0);

    $holding = holdingFor($client, 'AAPL');
    assertTrue($holding !== null, 'the buy should produce a holding');

    // 10 x 100 + 5 fee = 1005 cost basis, so 100.50 average.
    assertSame(10.0, (float)$holding['shares']);
    assertSame(1005.0, round((float)$holding['cost_basis'], 2));
    assertSame(100.5, round((float)$holding['avg_cost'], 2));
});

test('two buys average out by weight, not by price', function (TestClient $_c): void {
    $client = stocksClient('average');
    trade($client, 'MSFT', 'buy', 10, 100.0, 0.0, '2026-01-10');
    trade($client, 'MSFT', 'buy', 30, 200.0, 0.0, '2026-01-20');

    $holding = holdingFor($client, 'MSFT');

    // (10x100 + 30x200) / 40 = 175, not the midpoint of 150.
    assertSame(40.0, (float)$holding['shares']);
    assertSame(7000.0, round((float)$holding['cost_basis'], 2));
    assertSame(175.0, round((float)$holding['avg_cost'], 2));
});

test('a partial sell realises profit and leaves the rest at cost', function (TestClient $_c): void {
    $client = stocksClient('partial');
    trade($client, 'GOOG', 'buy', 100, 10.0, 0.0, '2026-01-10');
    trade($client, 'GOOG', 'sell', 40, 15.0, 0.0, '2026-02-10');

    $holding = holdingFor($client, 'GOOG');
    $summary = $client->json($client->get('/api/stocks/summary'));

    // Sold 40 at 15 against a 10 average: 200 realised. 60 left at cost 600.
    assertSame(60.0, (float)$holding['shares']);
    assertSame(600.0, round((float)$holding['cost_basis'], 2));
    assertSame(10.0, round((float)$holding['avg_cost'], 2), 'the average cost of what is left does not move');
    assertSame(200.0, round((float)$summary['totals']['realized_pl'], 2));
});

test('fees reduce realised profit', function (TestClient $_c): void {
    $client = stocksClient('fees');
    trade($client, 'TSLA', 'buy', 10, 100.0, 0.0, '2026-01-10');
    trade($client, 'TSLA', 'sell', 10, 120.0, 30.0, '2026-02-10');

    $summary = $client->json($client->get('/api/stocks/summary'));

    // 10 x (120 - 100) = 200 gross, less the 30 fee.
    assertSame(170.0, round((float)$summary['totals']['realized_pl'], 2));
});

test('selling everything closes the position', function (TestClient $_c): void {
    $client = stocksClient('closed');
    trade($client, 'AMD', 'buy', 25, 80.0, 0.0, '2026-01-10');
    trade($client, 'AMD', 'sell', 25, 90.0, 0.0, '2026-03-10');

    assertSame(null, holdingFor($client, 'AMD'), 'a fully sold position is no longer a holding');

    $summary = $client->json($client->get('/api/stocks/summary'));
    assertSame(250.0, round((float)$summary['totals']['realized_pl'], 2));
});

test('a loss is realised as a negative number', function (TestClient $_c): void {
    $client = stocksClient('loss');
    trade($client, 'INTC', 'buy', 10, 50.0, 0.0, '2026-01-10');
    trade($client, 'INTC', 'sell', 10, 30.0, 0.0, '2026-02-10');

    $summary = $client->json($client->get('/api/stocks/summary'));
    assertSame(-200.0, round((float)$summary['totals']['realized_pl'], 2));
});

test('an empty portfolio reports zeroes rather than failing', function (TestClient $_c): void {
    $client = stocksClient('empty');
    $summary = $client->json($client->get('/api/stocks/summary'));

    assertSame([], $summary['holdings'] ?? null);
    assertSame(0.0, round((float)($summary['totals']['realized_pl'] ?? 0), 2));
});

// --- Chart, watchlist, capital ---

test('the chart rejects an implausible year', function (TestClient $_c): void {
    $client = stocksClient('chart');

    assertSame(200, $client->get('/api/stocks/chart?year=2026')['status']);
    assertSame(422, $client->get('/api/stocks/chart?year=1200')['status']);
    assertSame(422, $client->get('/api/stocks/chart?year=9999')['status']);
});

test('a ticker can be watched and unwatched', function (TestClient $_c): void {
    $client = stocksClient('watch');

    // The route is named "toggle" but takes an explicit action; sending it
    // twice without one would just add the same ticker again.
    $client->post('/api/stocks/watchlists/toggle', ['ticker' => 'AAPL', 'market' => 'US', 'action' => 'add']);
    $on = $client->json($client->get('/api/stocks/watchlists'));
    assertContains('AAPL', array_column($on['watchlists'] ?? [], 'ticker'), 'the ticker should be watched');

    $client->post('/api/stocks/watchlists/toggle', ['ticker' => 'AAPL', 'market' => 'US', 'action' => 'remove']);
    $off = $client->json($client->get('/api/stocks/watchlists'));
    assertNotContains('AAPL', array_column($off['watchlists'] ?? [], 'ticker'), 'removing takes it off the list');
});

test('the watchlist refuses a malformed ticker', function (TestClient $_c): void {
    $client = stocksClient('watch');

    assertSame(422, $client->post('/api/stocks/watchlists/toggle', [
        'ticker' => 'not a ticker', 'market' => 'US', 'action' => 'add',
    ])['status']);
});

test('capital flows are recorded and validated', function (TestClient $_c): void {
    $client = stocksClient('capital');

    $created = $client->post('/api/stocks/capital', [
        'flow_type' => 'deposit', 'amount' => 50000, 'currency' => 'THB', 'flow_date' => '2026-01-05',
    ]);
    assertSame(201, $created['status'], 'deposit rejected: ' . $created['body']);

    assertSame(422, $client->post('/api/stocks/capital', [
        'flow_type' => 'deposit', 'amount' => 0, 'currency' => 'THB', 'flow_date' => '2026-01-05',
    ])['status'], 'a zero amount is not a capital flow');

    $flows = $client->json($client->get('/api/stocks/capital'));
    assertTrue(count($flows['flows'] ?? []) >= 1);
});

test('someone else cannot delete a capital flow', function (TestClient $_c): void {
    $client = stocksClient('capital');
    $flow = $client->json($client->post('/api/stocks/capital', [
        'flow_type' => 'deposit', 'amount' => 1000, 'currency' => 'THB', 'flow_date' => '2026-01-06',
    ]))['flow'];

    assertSame(404, stocksClient('intruder')->request('DELETE', '/api/stocks/capital/' . (int)$flow['id'], [])['status']);
});

// --- API keys ---

test('a stored provider key is never sent back in full', function (TestClient $_c): void {
    $client = stocksClient('keys');
    $secret = 'super-secret-provider-key-123456';

    assertSame(200, $client->post('/api/stocks/keys', ['provider' => 'finnhub', 'api_key' => $secret])['status']);

    $listing = $client->get('/api/stocks/keys');
    assertTrue(!str_contains($listing['body'], $secret), 'the raw key must not leave the server');
    assertStringContains('"set":true', $listing['body']);
});

test('a short or unknown provider key is refused', function (TestClient $_c): void {
    $client = stocksClient('keys');

    assertSame(422, $client->post('/api/stocks/keys', ['provider' => 'finnhub', 'api_key' => 'short'])['status']);
    assertSame(422, $client->post('/api/stocks/keys', ['provider' => 'not-a-provider', 'api_key' => 'long-enough-key'])['status']);
});

test('saving an empty key removes it', function (TestClient $_c): void {
    $client = stocksClient('keys');

    $client->post('/api/stocks/keys', ['provider' => 'finnhub', 'api_key' => 'another-long-enough-key']);
    $removed = $client->json($client->post('/api/stocks/keys', ['provider' => 'finnhub', 'api_key' => '']));
    assertTrue($removed['deleted'] ?? false, 'an empty key should delete the stored one');

    $listing = $client->json($client->get('/api/stocks/keys'));
    assertTrue(empty($listing['keys']['finnhub']['set']), 'the key should be gone');
});

// --- Access ---

test('every stocks endpoint needs a session', function (TestClient $_c): void {
    $anonymous = new TestClient(TEST_BASE_URL);

    foreach (['/api/stocks', '/api/stocks/summary', '/api/stocks/watchlists',
              '/api/stocks/capital', '/api/stocks/keys'] as $path) {
        assertContains($anonymous->get($path)['status'], [401, 302], $path . ' must require a session');
    }
});
