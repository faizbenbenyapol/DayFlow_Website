<?php
// =====================================================
// tests/integration/demo_reset_test.php
//
// The shared demo account is written to by every visitor. The nightly reset
// has to put back exactly what the owner set up — and must never touch a real
// account. Runs next to the database, inside the app container.
// =====================================================

declare(strict_types=1);

function demoScript(string $args = ''): string
{
    return (string)shell_exec(PHP_BINARY . ' ' . escapeshellarg(ROOT . '/scripts/demo-reset.php') . ' ' . $args . ' 2>&1');
}

function demoTaskTitles(int $userId): array
{
    $titles = DB::run('SELECT title FROM tasks WHERE user_id = ?', [$userId])->fetchAll(PDO::FETCH_COLUMN);
    sort($titles, SORT_STRING);   // not the database's collation: that is not what is under test
    return $titles;
}

/**
 * Runs $body with a temporary demo account and a clean snapshot slot, and
 * always puts the database and the snapshot file back as they were.
 * Tests run against a throwaway database, never a live one.
 */
function withDemoAccount(callable $body): void
{
    require_once ROOT . '/config/database.php';
    require_once ROOT . '/models/User.php';
    require_once ROOT . '/models/AccountData.php';
    require_once ROOT . '/core/DemoReset.php';

    // Only one account may be the demo. A database that already has one
    // (migration 018 flags a user named 'demo') hands it back afterwards.
    $existingDemos = DB::run('SELECT id FROM users WHERE is_demo = 1')->fetchAll(PDO::FETCH_COLUMN);
    DB::run('UPDATE users SET is_demo = 0 WHERE is_demo = 1');

    $path   = DemoReset::snapshotPath();
    $backup = is_file($path) ? file_get_contents($path) : null;
    @unlink($path);

    $demoId  = User::create('demo_t_' . TEST_RUN_ID, 'demo_t_' . TEST_RUN_ID . '@example.test', 'TestPass123!');
    $otherId = User::create('real_t_' . TEST_RUN_ID, 'real_t_' . TEST_RUN_ID . '@example.test', 'TestPass123!');
    DB::run('UPDATE users SET is_demo = 1 WHERE id = ?', [$demoId]);

    try {
        $body($demoId, $otherId);
    } finally {
        DB::run('DELETE FROM users WHERE id IN (?, ?)', [$demoId, $otherId]);
        foreach ($existingDemos as $id) DB::run('UPDATE users SET is_demo = 1 WHERE id = ?', [$id]);
        @unlink($path);
        if ($backup !== null) file_put_contents($path, $backup);
    }
}

test('the demo account returns to its snapshot and nobody else is touched', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $otherId): void {
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$demoId, 'ต้นแบบ']);
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$otherId, 'ของคนจริง']);

        assertStringContains('บันทึก snapshot แล้ว', demoScript('--snapshot'));

        // What visitors do in a day: add junk, delete the sample.
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$demoId, 'ขยะจากผู้เยี่ยมชม']);
        DB::run('DELETE FROM tasks WHERE user_id = ? AND title = ?', [$demoId, 'ต้นแบบ']);
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$otherId, 'งานใหม่ของคนจริง']);

        assertStringContains('รีเซ็ตบัญชี demo แล้ว', demoScript());

        assertSame(['ต้นแบบ'], demoTaskTitles($demoId), 'the demo is back to the sample data');
        assertSame(['ของคนจริง', 'งานใหม่ของคนจริง'], demoTaskTitles($otherId), 'a real account is never reset');
    });
});

test('the snapshot never carries the account row or its password hash', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $_otherId): void {
        demoScript('--snapshot');
        $raw = (string)file_get_contents(DemoReset::snapshotPath());

        assertFalse(str_contains($raw, 'password_hash'), 'no credentials in the file');
        assertFalse(isset(json_decode($raw, true)['user']), 'no account row');
    });
});

test('resetting without a snapshot fails loudly and changes nothing', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $_otherId): void {
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$demoId, 'ต้องไม่หาย']);

        $output = demoScript();
        assertStringContains('snapshot', $output);
        assertSame(['ต้องไม่หาย'], demoTaskTitles($demoId), 'no snapshot means no reset');
    });
});

test('a corrupt snapshot is refused rather than wiping the demo', function (TestClient $_c): void {
    withDemoAccount(function (int $demoId, int $_otherId): void {
        DB::run('INSERT INTO tasks (user_id, title) VALUES (?, ?)', [$demoId, 'ต้องไม่หาย']);
        file_put_contents(DemoReset::snapshotPath(), '{not json');

        assertStringContains('เสียหาย', demoScript());
        assertSame(['ต้องไม่หาย'], demoTaskTitles($demoId));
    });
});
