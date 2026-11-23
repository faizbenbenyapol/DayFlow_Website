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

// =====================================================
// Refresh, analysis and key checks — the paths that need no outside API
// =====================================================

test('refreshing prices without a price provider asks for one', function (TestClient $_c): void {
    $client = stocksClient('noprovider');
    trade($client, 'MSFT', 'buy', 1, 100);

    $response = $client->post('/api/stocks/refresh', []);
    assertSame(422, $response['status']);
    assertStringContains('API หุ้น', $response['body']);
});

test('the automatic refresh on page load is quiet when no provider is set', function (TestClient $_c): void {
    $client = stocksClient('noprovider');
    trade($client, 'MSFT', 'buy', 1, 100);

    $response = $client->post('/api/stocks/refresh', ['auto' => true]);
    assertSame(200, $response['status'], 'the page must not log an error for an unconfigured provider');
    assertSame(false, $client->json($response)['configured']);
});

test('an analysis needs a valid ticker and an AI key', function (TestClient $_c): void {
    $client = stocksClient('analysis');

    assertSame(422, $client->post('/api/stocks/analyze', ['ticker' => 'not a ticker!'])['status'], 'bad ticker');

    $response = $client->post('/api/stocks/analyze', ['ticker' => 'AAPL', 'market' => 'US']);
    assertSame(422, $response['status'], 'no AI key configured');
    assertStringContains('API Key', $response['body']);
});

test('testing a price key checks the provider and needs a key', function (TestClient $_c): void {
    $client = stocksClient('keytest');

    assertSame(422, $client->post('/api/stocks/keys/test', ['provider' => 'nope', 'api_key' => 'x'])['status'], 'unknown provider');
    assertSame(422, $client->post('/api/stocks/keys/test', ['provider' => 'finnhub'])['status'], 'no key saved or sent');
});

// A provider that sends a price and no ratios must leave the ratios blank. The
// cache used to fill them in from a hash of the ticker, which put made-up P/E
// and EPS figures on the page next to real prices.
test('a quote without ratios is stored without them', function (TestClient $_c): void {
    require_once ROOT . '/config/database.php';
    require_once ROOT . '/models/StockPriceCache.php';

    $ticker = 'ZZ' . strtoupper(substr(TEST_RUN_ID, 0, 6));
    try {
        StockPriceCache::upsert($ticker, 10.5, 10.0, 'USD');
        $row = StockPriceCache::get($ticker);
        assertTrue($row !== null, 'the quote is cached');
        foreach (['pe_ratio', 'forward_pe', 'peg_ratio', 'p_fcf_ratio', 'eps'] as $column) {
            assertSame(null, $row[$column], $column . ' stays unknown');
        }

        StockPriceCache::upsert($ticker, 10.5, 10.0, 'USD', 12.345, null, null, null, 1.2);
        $row = StockPriceCache::get($ticker);
        assertSame('12.35', (string)$row['pe_ratio'], 'a ratio the provider gave is kept, rounded');
        assertSame(null, $row['peg_ratio'], 'and one it did not give is still blank');
    } finally {
        DB::run('DELETE FROM stock_price_cache WHERE ticker = ?', [$ticker]);
    }
});

// --- เงินจากการขาย: กำไรต่อครั้ง ลงทุนต่อ หรือถอนออก (feedback-2026-11-r2 REQ-11–15) ---

/** Records a sale with a choice for its proceeds; returns the created transaction. */
function sale(TestClient $client, string $ticker, float $qty, float $price, float $fee, array $proceeds, string $date = '2026-03-01'): array
{
    $response = $client->post('/api/stocks', array_merge([
        'ticker'   => $ticker,
        'market'   => 'US',
        'side'     => 'sell',
        'quantity' => $qty,
        'price'    => $price,
        'fee'      => $fee,
        'currency' => 'USD',
        'txn_date' => $date,
    ], $proceeds));

    if ($response['status'] !== 201) {
        throw new RuntimeException("sale rejected ({$response['status']}): " . $response['body']);
    }
    return $client->json($response)['transaction'];
}

// กำไรต่อรายการขายต้องคำนวณจากต้นทุนเฉลี่ย ณ ตอนขาย
test('a sale reports its own realised profit on its row', function (TestClient $_c): void {
    $client = stocksClient('pl_sale');
    trade($client, 'AAPL', 'buy', 10, 100.0, 0.0, '2026-01-10');
    trade($client, 'AAPL', 'buy', 10, 200.0, 0.0, '2026-01-11');
    $sold = sale($client, 'AAPL', 5, 180.0, 2.0, ['proceeds_action' => 'reinvest'], '2026-01-12');

    $list = $client->json($client->get('/api/stocks?ticker=AAPL'));
    $rows = $list['transactions'] ?? [];
    assertSame(3, count($rows), 'three trades expected');

    $sawSale = false;
    foreach ($rows as $row) {
        if ((int)$row['id'] === (int)$sold['id']) {
            $sawSale = true;
            assertSame(148.0, round((float)$row['realized_pl'], 2), 'the sale profit is (180 - 150) * 5 - 2');
            assertSame('reinvest', $row['proceeds_action'] ?? null, 'proceeds choice is stored on the row');
        } else {
            assertSame(null, $row['realized_pl'], 'buys carry no realised profit');
        }
    }
    assertTrue($sawSale, 'the sale row is listed');
});

// ผลรวมกำไรต่อรายการต้องเท่ากับยอดรวมในพอร์ต
test('per-sale profits add up to the portfolio total', function (TestClient $_c): void {
    $client = stocksClient('pl_sum');
    // MSFT: full cycle with fees on both sides.
    trade($client, 'MSFT', 'buy', 10, 100.0, 5.0, '2026-01-05');
    sale($client, 'MSFT', 10, 110.0, 5.0, ['proceeds_action' => 'reinvest'], '2026-02-05');
    // GOOG: partial sale, then a losing sale with a fee on top.
    trade($client, 'GOOG', 'buy', 100, 10.0, 10.0, '2026-01-06');
    sale($client, 'GOOG', 40, 15.0, 0.0, ['proceeds_action' => 'reinvest'], '2026-02-06');
    sale($client, 'GOOG', 20, 5.0, 4.0, ['proceeds_action' => 'reinvest'], '2026-02-07');

    $all = $client->json($client->get('/api/stocks'));
    $sum = 0.0;
    $sells = 0;
    foreach ($all['transactions'] ?? [] as $row) {
        if ($row['realized_pl'] !== null) {
            $sum += (float)$row['realized_pl'];
            $sells++;
        }
    }
    assertSame(3, $sells, 'all three sells have a realised figure');

    $summary = $client->json($client->get('/api/stocks/summary'));
    $total = (float)$summary['totals']['realized_pl'];
    assertTrue(abs(round($sum, 2) - round($total, 2)) <= 0.01, 'row sums must match the summary total');
});

// แก้ไขรายการซื้อต้องคำนวณกำไรของรายการขายใหม่
test('editing an earlier buy re-prices a later sale', function (TestClient $_c): void {
    $client = stocksClient('pl_edit');
    $buy = trade($client, 'AAPL', 'buy', 10, 100.0, 0.0, '2026-01-10');
    $sold = sale($client, 'AAPL', 10, 150.0, 0.0, ['proceeds_action' => 'reinvest'], '2026-02-10');

    $list = $client->json($client->get('/api/stocks?ticker=AAPL'));
    $plBefore = null;
    foreach ($list['transactions'] ?? [] as $row) {
        if ((int)$row['id'] === (int)$sold['id']) $plBefore = (float)$row['realized_pl'];
    }
    assertSame(500.0, round((float)$plBefore, 2), 'before edit, profit is (150 - 100) * 10');

    $resp = $client->request('PUT', '/api/stocks/' . (int)$buy['id'], [
        'ticker' => 'AAPL', 'market' => 'US', 'side' => 'buy',
        'quantity' => 10, 'price' => 120.0, 'fee' => 0.0, 'currency' => 'USD', 'txn_date' => '2026-01-10',
    ]);
    assertSame(200, $resp['status'], 'the buy edit must succeed: ' . $resp['body']);

    $list2 = $client->json($client->get('/api/stocks?ticker=AAPL'));
    $plAfter = null;
    foreach ($list2['transactions'] ?? [] as $row) {
        if ((int)$row['id'] === (int)$sold['id']) $plAfter = (float)$row['realized_pl'];
    }
    assertSame(300.0, round((float)$plAfter, 2), 'the sale re-prices off the adjusted cost (150 - 120) * 10');
});

// ขายหุ้นที่ยังไม่เคยซื้อ ขาดทุนเท่ากับค่าธรรมเนียมเท่านั้น
test('selling a ticker never bought loses only the fee', function (TestClient $_c): void {
    $client = stocksClient('pl_phantom');
    $sold = sale($client, 'NFLX', 5, 10.0, 1.0, ['proceeds_action' => 'reinvest'], '2026-02-10');

    $list = $client->json($client->get('/api/stocks?ticker=NFLX'));
    $found = false;
    foreach ($list['transactions'] ?? [] as $row) {
        if ((int)$row['id'] === (int)$sold['id']) {
            $found = true;
            assertSame(-1.0, round((float)$row['realized_pl'], 2), 'a phantom sale realises only the fee');
        }
    }
    assertTrue($found, 'the phantom sale appears in the list');
});

// การนำเงินกลับมาลงทุนใหม่ไม่กระทบเงินทุน
test('reinvesting proceeds leaves capital alone', function (TestClient $_c): void {
    $client = stocksClient('pl_reinvest');
    $client->post('/api/stocks/capital', [
        'flow_type' => 'deposit', 'amount' => 1000, 'currency' => 'USD', 'flow_date' => '2026-01-02',
    ]);

    trade($client, 'AAPL', 'buy', 10, 50.0, 0.0, '2026-01-10');
    $sold = sale($client, 'AAPL', 10, 60.0, 0.0, ['proceeds_action' => 'reinvest'], '2026-02-10');

    $summary = $client->json($client->get('/api/stocks/summary'));
    $net = (float)($summary['totals']['capital']['USD']['net_capital'] ?? 0);
    assertSame(1000.0, round($net, 2), 'a reinvest does not touch capital');

    $flows = $client->json($client->get('/api/stocks/capital'));
    $linkedToSale = 0;
    foreach ($flows['flows'] ?? [] as $flow) {
        if (!empty($flow['sale_txn_id']) && (int)$flow['sale_txn_id'] === (int)$sold['id']) $linkedToSale++;
    }
    assertSame(0, $linkedToSale, 'no flow is linked to a reinvest sale');
});

// การขายแบบ withdraw ต้องสร้างรายการถอนเงินที่ผูกกับรายการขาย
test('withdrawing records a linked withdrawal', function (TestClient $_c): void {
    $client = stocksClient('wd_link');

    assertSame(201, $client->post('/api/stocks/capital', [
        'flow_type' => 'deposit', 'amount' => 1000, 'currency' => 'USD', 'flow_date' => '2026-01-05',
    ])['status'], 'opening deposit');

    trade($client, 'AAPL', 'buy', 10, 50.0, 0.0, '2026-01-10');
    $sell = sale($client, 'AAPL', 10, 60.0, 0.0, [
        'proceeds_action'   => 'withdraw',
        'withdraw_amount'   => 500.0,
        'withdraw_currency' => 'USD',
    ], '2026-03-01');

    $flows = $client->json($client->get('/api/stocks/capital'))['flows'] ?? [];
    $linked = array_values(array_filter($flows, static fn(array $f): bool => (int)($f['sale_txn_id'] ?? 0) === (int)$sell['id']));

    assertSame(1, count($linked), 'exactly one flow linked to the sale');
    assertSame('withdrawal', $linked[0]['flow_type']);
    assertSame(500.0, round((float)$linked[0]['amount'], 2));
    assertSame('USD', $linked[0]['currency']);

    $summary = $client->json($client->get('/api/stocks/summary'));
    assertSame(500.0, round((float)$summary['totals']['capital']['USD']['net_capital'], 2), 'net capital drops by the withdrawal');

    $list = $client->json($client->get('/api/stocks?ticker=AAPL'));
    $row = array_values(array_filter($list['transactions'] ?? [], static fn(array $t): bool => (int)$t['id'] === (int)$sell['id']));
    assertSame(1, count($row), 'the sale is listed');
    assertSame(500.0, round((float)$row[0]['withdraw_amount'], 2), 'the sale remembers its withdrawal amount');
});

// เปลี่ยน sale กลับเป็น reinvest หรือลบ sale ต้องลบรายการถอนที่ผูกอยู่ออก
test('switching to reinvest or deleting the sale removes the withdrawal', function (TestClient $_c): void {
    $client = stocksClient('wd_toggle');

    assertSame(201, $client->post('/api/stocks/capital', [
        'flow_type' => 'deposit', 'amount' => 1000, 'currency' => 'USD', 'flow_date' => '2026-01-05',
    ])['status'], 'opening deposit');

    trade($client, 'MSFT', 'buy', 10, 100.0, 0.0, '2026-01-10');
    $sell = sale($client, 'MSFT', 10, 120.0, 0.0, [
        'proceeds_action'   => 'withdraw',
        'withdraw_amount'   => 500.0,
        'withdraw_currency' => 'USD',
    ], '2026-03-01');

    $full = [
        'ticker' => 'MSFT', 'market' => 'US', 'side' => 'sell',
        'quantity' => 10, 'price' => 120.0, 'fee' => 0.0, 'currency' => 'USD',
        'txn_date' => '2026-03-01',
    ];

    // Back to reinvest: the linked flow must disappear.
    $put = $client->request('PUT', '/api/stocks/' . (int)$sell['id'], $full + ['proceeds_action' => 'reinvest']);
    assertContains($put['status'], [200, 204], 'PUT sale back to reinvest');

    $flows = $client->json($client->get('/api/stocks/capital'))['flows'] ?? [];
    $linked = array_filter($flows, static fn(array $f): bool => $f['sale_txn_id'] !== null);
    assertSame(0, count($linked), 'no linked flow after reinvesting');

    $summary = $client->json($client->get('/api/stocks/summary'));
    assertSame(1000.0, round((float)$summary['totals']['capital']['USD']['net_capital'], 2), 'net capital is back to the deposit');

    // Back to withdraw, this time 200.
    $put = $client->request('PUT', '/api/stocks/' . (int)$sell['id'], $full + [
        'proceeds_action'   => 'withdraw',
        'withdraw_amount'   => 200.0,
        'withdraw_currency' => 'USD',
    ]);
    assertContains($put['status'], [200, 204], 'PUT sale back to withdraw');

    $flows = $client->json($client->get('/api/stocks/capital'))['flows'] ?? [];
    $linked = array_values(array_filter($flows, static fn(array $f): bool => (int)($f['sale_txn_id'] ?? 0) === (int)$sell['id']));
    assertSame(1, count($linked), 'one linked flow again');
    assertSame(200.0, round((float)$linked[0]['amount'], 2), 'the new amount is used');

    // Delete the sale: the linked flow goes with it.
    $del = $client->request('DELETE', '/api/stocks/' . (int)$sell['id'], []);
    assertContains($del['status'], [200, 204], 'deleting the sale succeeds');

    $flows = $client->json($client->get('/api/stocks/capital'))['flows'] ?? [];
    $linked = array_filter($flows, static fn(array $f): bool => $f['sale_txn_id'] !== null);
    assertSame(0, count($linked), 'no linked flow after deleting the sale');

    $summary = $client->json($client->get('/api/stocks/summary'));
    assertSame(1000.0, round((float)$summary['totals']['capital']['USD']['net_capital'], 2), 'net capital is back to the deposit');
});

// รายการถอนที่ผูกกับ sale แก้ไขได้ทาง sale เท่านั้น คนอื่นเห็นเป็น 404
test('a linked withdrawal is edited through its sale only', function (TestClient $_c): void {
    $client = stocksClient('wd_locked');

    assertSame(201, $client->post('/api/stocks/capital', [
        'flow_type' => 'deposit', 'amount' => 1000, 'currency' => 'USD', 'flow_date' => '2026-01-05',
    ])['status'], 'opening deposit');

    trade($client, 'NVDA', 'buy', 10, 100.0, 0.0, '2026-01-10');
    $sell = sale($client, 'NVDA', 10, 150.0, 0.0, [
        'proceeds_action'   => 'withdraw',
        'withdraw_amount'   => 500.0,
        'withdraw_currency' => 'USD',
    ], '2026-03-01');

    $flows = $client->json($client->get('/api/stocks/capital'))['flows'] ?? [];
    $linked = array_values(array_filter($flows, static fn(array $f): bool => (int)($f['sale_txn_id'] ?? 0) === (int)$sell['id']));
    assertSame(1, count($linked), 'the linked flow exists');

    $flowId = (int)$linked[0]['id'];

    assertSame(409, $client->request('PUT', '/api/stocks/capital/' . $flowId, [
        'flow_type' => 'withdrawal', 'amount' => 999, 'currency' => 'USD', 'flow_date' => '2026-03-01',
    ])['status'], 'a linked flow cannot be edited directly');

    assertSame(409, $client->request('DELETE', '/api/stocks/capital/' . $flowId, [])['status'], 'a linked flow cannot be deleted directly');

    assertSame(404, stocksClient('outsider2')->request('DELETE', '/api/stocks/capital/' . $flowId, [])['status'], 'a different account sees 404');
});

// proceeds_action ต้องถูกตรวจสอบ และการถอนต้องมีจำนวน/สกุลเงินที่ถูกต้อง
test('the proceeds choice is validated', function (TestClient $_c): void {
    $client = stocksClient('wd_validate');
    $base = [
        'ticker' => 'AAPL', 'market' => 'US', 'side' => 'sell',
        'quantity' => 1, 'price' => 10, 'fee' => 0, 'currency' => 'USD', 'txn_date' => '2026-03-01',
    ];

    $cases = [
        'unknown action'     => ['proceeds_action' => 'gift'],
        'withdraw zero'      => ['proceeds_action' => 'withdraw', 'withdraw_amount' => 0, 'withdraw_currency' => 'USD'],
        'withdraw bad money' => ['proceeds_action' => 'withdraw', 'withdraw_amount' => 100, 'withdraw_currency' => 'EUR'],
    ];

    foreach ($cases as $label => $override) {
        assertSame(422, $client->post('/api/stocks', $override + $base)['status'], $label . ' should be refused');
    }
});

// sale แบบเก่าที่ไม่ส่ง proceeds_action ต้องไม่มีทั้งตัวเลือกและรายการถอน
test('an old-style sale has no choice and no withdrawal', function (TestClient $_c): void {
    $client = stocksClient('wd_legacy');

    assertSame(201, $client->post('/api/stocks/capital', [
        'flow_type' => 'deposit', 'amount' => 1000, 'currency' => 'USD', 'flow_date' => '2026-01-05',
    ])['status'], 'opening deposit');

    trade($client, 'AMD', 'buy', 10, 50.0, 0.0, '2026-01-10');
    $sell = trade($client, 'AMD', 'sell', 10, 60.0, 0.0, '2026-03-01');

    $list = $client->json($client->get('/api/stocks?ticker=AMD'));
    $row = array_values(array_filter($list['transactions'] ?? [], static fn(array $t): bool => (int)$t['id'] === (int)$sell['id']));
    assertSame(1, count($row), 'the sale is listed');
    assertSame(null, $row[0]['proceeds_action'] ?? null, 'an old-style sale has no proceeds choice');

    $flows = $client->json($client->get('/api/stocks/capital'))['flows'] ?? [];
    $linked = array_filter($flows, static fn(array $f): bool => $f['sale_txn_id'] !== null);
    assertSame(0, count($linked), 'no flow is created by an old-style sale');

    $summary = $client->json($client->get('/api/stocks/summary'));
    assertSame(1000.0, round((float)$summary['totals']['capital']['USD']['net_capital'], 2), 'net capital is unchanged');
});

// ต้นทุนเฉลี่ยมีเศษเกินสองตำแหน่ง: ผลรวมกำไรรายครั้งยังต้องเท่ายอดในหน้าสรุปพอร์ต (ข้อค้นพบ F3 ของ reviewer)
test('fractional per-sale profits still add up to the portfolio total', function (TestClient $_c): void {
    $client = stocksClient('pl_fraction');
    trade($client, 'FRAC', 'buy', 3, 10.0, 0.0, '2026-01-10');
    foreach (['2026-02-01', '2026-02-02', '2026-02-03'] as $date) {
        sale($client, 'FRAC', 1, 10.0051, 0.0, ['proceeds_action' => 'reinvest'], $date);
    }

    $sum = 0.0;
    foreach ($client->json($client->get('/api/stocks?ticker=FRAC'))['transactions'] as $row) {
        $sum += (float)($row['realized_pl'] ?? 0);
    }
    $total = (float)$client->json($client->get('/api/stocks/summary'))['totals']['realized_pl'];
    assertSame($total, round($sum, 2), 'rows must not be rounded one by one before they are added');
});

// ยอดถอนที่เกินขนาดคอลัมน์ต้องถูกปฏิเสธเป็น 422 ไม่ใช่ 500 (ข้อค้นพบ F6)
test('an impossible withdrawal amount is refused, not a server error', function (TestClient $_c): void {
    $client = stocksClient('wd_huge');
    $response = $client->post('/api/stocks', [
        'ticker' => 'BIG', 'market' => 'US', 'side' => 'sell', 'quantity' => 1, 'price' => 10, 'fee' => 0,
        'currency' => 'USD', 'txn_date' => '2026-03-01',
        'proceeds_action' => 'withdraw', 'withdraw_amount' => 1e20, 'withdraw_currency' => 'USD',
    ]);
    assertSame(422, $response['status'], $response['body']);
});
