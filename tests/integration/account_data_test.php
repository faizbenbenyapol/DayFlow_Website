<?php
// =====================================================
// tests/integration/account_data_test.php
//
// Personal backup: what export writes must be exactly what import restores,
// references between rows must survive the fresh ids an import hands out, and
// a file may only ever replace the sections it actually carries.
// =====================================================

declare(strict_types=1);

function accountClient(string $label): TestClient
{
    $client = new TestClient(TEST_BASE_URL);
    $client->login('acct_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
}

function accountImport(TestClient $client, array $data): array
{
    $client->get('/settings');
    return $client->upload('/api/settings/import', 'file', 'backup.json', json_encode($data, JSON_UNESCAPED_UNICODE));
}

function accountExport(TestClient $client): array
{
    $response = $client->get('/api/settings/export');
    assertSame(200, $response['status'], 'export failed: ' . substr($response['body'], 0, 200));
    return $client->json($response);
}

/** Looks a row up by id in an exported table. */
function rowById(array $rows, mixed $id): ?array
{
    foreach ($rows as $row) {
        if ((string)($row['id'] ?? '') === (string)$id) return $row;
    }
    return null;
}

/**
 * One row in every section, with every kind of reference between them. The
 * ids are arbitrary: an import must never reuse them.
 */
function accountFixture(): array
{
    return [
        'format' => 'dayflow-export', 'version' => 2,
        'settings' => ['theme' => 'ocean', 'timezone' => 'Asia/Tokyo', 'menu_order' => '["notes","tasks"]'],
        'tasks'               => [['id' => 9001, 'title' => 'งานสำรอง', 'quadrant' => 1, 'status' => 'open']],
        'notes'               => [['id' => 9002, 'title' => 'โน้ตสำรอง']],
        'note_tags'           => [['id' => 9003, 'name' => 'แท็กสำรอง']],
        'note_blocks'         => [['id' => 9004, 'note_id' => 9002, 'type' => 'text', 'content' => 'บล็อกสำรอง', 'position' => 0]],
        'note_tag_relations'  => [['note_id' => 9002, 'tag_id' => 9003]],
        'calendar_events'     => [['id' => 9005, 'title' => 'นัดสำรอง', 'start_datetime' => '2026-10-01 09:00:00', 'end_datetime' => '2026-10-01 10:00:00']],
        'daily_todos'         => [['id' => 9006, 'todo_date' => '2026-10-01', 'title' => 'ทำสำรอง']],
        'exercise_categories' => [['id' => 9007, 'name' => 'หมวดสำรอง']],
        'workouts'            => [['id' => 9008, 'workout_date' => '2026-10-01', 'type' => 'หมวดสำรอง', 'duration_min' => 30]],
        'finance_categories'  => [['id' => 9009, 'name' => 'หมวดเงินสำรอง', 'type' => 'expense']],
        'finances'            => [['id' => 9010, 'type' => 'expense', 'amount' => '50.00', 'category_id' => 9009, 'txn_date' => '2026-10-01']],
        'subscriptions'       => [['id' => 9011, 'name' => 'สมาชิกสำรอง', 'amount' => '99.00', 'billing_cycle' => 'monthly', 'next_due_date' => '2026-10-15']],
        'dashboard_layout'    => [['id' => 9012, 'widget_key' => 'tasks', 'position' => 0, 'is_visible' => 1]],
        'food_notes'          => [['id' => 9013, 'name' => 'อาหารสำรอง', 'type' => 'food', 'reaction' => 'allergy']],
        'skills'              => [['id' => 'old-skill-uuid', 'name' => 'ทักษะสำรอง', 'target_hours' => 10, 'color' => '#3b82f6']],
        'skill_logs'          => [['id' => 'old-log-uuid', 'skill_id' => 'old-skill-uuid', 'start_time' => '2026-10-01 09:00:00', 'end_time' => '2026-10-01 10:00:00', 'duration_seconds' => 3600]],
        'focus_sessions'      => [['id' => 9014, 'task_id' => 9001, 'title' => 'โฟกัสสำรอง', 'duration_min' => 25, 'type' => 'work']],
        'habits'              => [['id' => 9015, 'name' => 'นิสัยสำรอง', 'target_days' => 7]],
        'habit_logs'          => [['id' => 9016, 'habit_id' => 9015, 'log_date' => '2026-10-01']],
        'quick_items'         => [['id' => 9017, 'content' => 'จดด่วนสำรอง']],
        'bookmarks'           => [['id' => 9018, 'title' => 'ลิงก์สำรอง', 'url' => 'https://example.com', 'category' => 'ทั่วไป']],
        'stock_transactions'  => [['id' => 9019, 'ticker' => 'AAPL', 'market' => 'US', 'side' => 'buy', 'quantity' => '1', 'price' => '100', 'fee' => '0', 'currency' => 'USD', 'txn_date' => '2026-10-01']],
        'stock_watchlists'    => [['id' => 9020, 'ticker' => 'MSFT', 'market' => 'US']],
        'stock_capital_flows' => [['id' => 9021, 'flow_type' => 'deposit', 'amount' => '1000', 'currency' => 'USD', 'flow_date' => '2026-10-01']],
        'ai_generations'      => [['id' => 9022, 'kind' => 'script', 'keyword' => 'สำรอง', 'status' => 'completed']],
    ];
}

// =====================================================

test('a backup restores every section into another account', function (TestClient $_c): void {
    $fixture = accountFixture();
    $tables = array_values(array_diff(array_keys($fixture), ['format', 'version', 'settings']));

    $first = accountClient('first');
    $response = accountImport($first, $fixture);
    assertSame(200, $response['status'], 'fixture import failed: ' . substr($response['body'], 0, 300));
    assertSame(count($tables), $first->json($response)['total'] ?? null, 'every fixture row should land');

    // Round-trip through a second, unrelated account.
    $second = accountClient('second');
    assertSame(200, accountImport($second, accountExport($first))['status']);
    $restored = accountExport($second);

    foreach ($tables as $table) {
        assertSame(1, count($restored[$table] ?? []), "{$table} should survive the round trip");
    }

    // Fresh ids everywhere, and every reference follows its parent.
    $note = $restored['notes'][0];
    assertFalse((string)$note['id'] === '9002', 'ids from the file must not be reused');
    assertSame((string)$note['id'], (string)$restored['note_blocks'][0]['note_id'], 'block → note');
    assertSame((string)$note['id'], (string)$restored['note_tag_relations'][0]['note_id'], 'tag link → note');
    assertSame((string)$restored['note_tags'][0]['id'], (string)$restored['note_tag_relations'][0]['tag_id'], 'tag link → tag');
    assertSame((string)$restored['finance_categories'][0]['id'], (string)$restored['finances'][0]['category_id'], 'finance → category');
    assertSame((string)$restored['habits'][0]['id'], (string)$restored['habit_logs'][0]['habit_id'], 'log → habit');
    assertSame((string)$restored['tasks'][0]['id'], (string)$restored['focus_sessions'][0]['task_id'], 'focus → task');
    assertSame($restored['skills'][0]['id'], $restored['skill_logs'][0]['skill_id'], 'log → skill');
    assertFalse($restored['skills'][0]['id'] === 'old-skill-uuid', 'a UUID id is regenerated too');

    // And the restored note is really usable through the app.
    $blocks = $second->json($second->get('/api/notes/' . (int)$note['id'] . '/blocks'))['blocks'] ?? [];
    assertSame(['บล็อกสำรอง'], array_column($blocks, 'content'));
});

test('importing the same backup twice replaces instead of duplicating', function (TestClient $_c): void {
    $client = accountClient('twice');
    accountImport($client, accountFixture());
    accountImport($client, accountFixture());

    $export = accountExport($client);
    assertSame(1, count($export['tasks']));
    assertSame(1, count($export['note_blocks']));
    assertSame(1, count($export['habit_logs']));
});

test('only the sections in the file are replaced', function (TestClient $_c): void {
    $client = accountClient('partial');
    accountImport($client, accountFixture());

    $response = accountImport($client, ['bookmarks' => [
        ['title' => 'ใหม่ 1', 'url' => 'https://example.org/1', 'category' => 'x'],
        ['title' => 'ใหม่ 2', 'url' => 'https://example.org/2', 'category' => 'x'],
    ]]);
    assertSame(200, $response['status']);

    $export = accountExport($client);
    assertSame(['ใหม่ 1', 'ใหม่ 2'], array_column($export['bookmarks'], 'title'), 'bookmarks are replaced');
    assertSame(1, count($export['tasks']), 'tasks were not in the file and must be untouched');
    assertSame(1, count($export['food_notes']), 'food notes were not in the file and must be untouched');
    assertSame(1, count($export['skill_logs']), 'skill logs were not in the file and must be untouched');
});

test('a bad row rolls the whole import back', function (TestClient $_c): void {
    $client = accountClient('rollback');
    accountImport($client, accountFixture());

    $response = accountImport($client, [
        'tasks'     => [['title' => 'ใหม่', 'quadrant' => 1]],
        'finances'  => [['type' => 'expense', 'amount' => '1', 'txn_date' => 'ไม่ใช่วันที่']],
    ]);
    assertSame(422, $response['status'], 'a value the column refuses is the file\'s fault');
    assertStringContains('finances', $response['body'], 'the message names the section');

    $export = accountExport($client);
    assertSame(['งานสำรอง'], array_column($export['tasks'], 'title'), 'nothing may change on a failed import');
});

test('a file that is not a DayFlow backup is refused', function (TestClient $_c): void {
    $client = accountClient('refuse');

    assertSame(422, accountImport($client, ['format' => 'something-else', 'tasks' => []])['status'], 'wrong format');
    assertSame(422, accountImport($client, ['version' => 99, 'tasks' => []])['status'], 'newer version');
    assertSame(422, accountImport($client, ['hello' => 'world'])['status'], 'no known section');
});

test('settings travel, credentials do not', function (TestClient $_c): void {
    $client = accountClient('settings');
    accountImport($client, accountFixture());

    $settings = $client->json($client->get('/api/settings'))['settings'] ?? [];
    assertSame('ocean', $settings['theme'] ?? null);
    assertSame('Asia/Tokyo', $settings['timezone'] ?? null);

    // An invalid value keeps the current one instead of failing the import.
    assertSame(200, accountImport($client, ['settings' => ['theme' => 'hacker', 'timezone' => 'Mars/Base']])['status']);
    $settings = $client->json($client->get('/api/settings'))['settings'] ?? [];
    assertSame('ocean', $settings['theme'] ?? null);

    $export = accountExport($client);
    assertSame('dayflow-export', $export['format'] ?? null);
    assertFalse(array_key_exists('telegram_bot_token', $export['settings'] ?? []), 'no Telegram token');
    foreach (['user_ai_keys', 'user_stock_api_keys', 'remember_tokens', 'app_shares', 'files', 'projects'] as $table) {
        assertFalse(array_key_exists($table, $export), "{$table} must not be exported");
    }
    assertFalse(array_key_exists('password_hash', $export['user'] ?? []), 'no password hash');
});
