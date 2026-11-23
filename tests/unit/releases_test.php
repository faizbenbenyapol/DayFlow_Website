<?php
// =====================================================
// tests/unit/releases_test.php
//
// ข้อความอัปเดตที่ผู้ใช้อ่านในหน้าตั้งค่า > เกี่ยวกับแอป (REQ-13, REQ-14)
// ต้องอ่านเข้าใจโดยคนทั่วไป และไม่บอกรายละเอียดภายในของระบบ
// =====================================================

declare(strict_types=1);

/** รายการเวอร์ชันจากไฟล์จริง ใหม่สุดอยู่บนสุด */
function releases(): array
{
    return require ROOT . '/config/releases.php';
}

test('every release has a version, a title and something it changed', function (): void {
    $releases = releases();
    assertTrue(count($releases) >= 2, 'the current version and at least one before it');
    foreach ($releases as $release) {
        assertTrue((bool)preg_match('/^\d+\.\d+\.\d+$/', $release['version']), "{$release['version']} is a plain version number");
        assertTrue(trim($release['title']) !== '', "{$release['version']} has a title");
        assertTrue(count($release['changes']) > 0, "{$release['version']} lists what changed");
        assertTrue($release['date'] === null || (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $release['date']), "{$release['version']} has a date or none");
    }
});

test('the newest release comes first and no version is listed twice', function (): void {
    $versions = array_column(releases(), 'version');
    $sorted = $versions;
    usort($sorted, fn(string $a, string $b): int => version_compare($b, $a));
    assertSame($sorted, $versions);
    assertSame(count($versions), count(array_unique($versions)));
});

test('release notes speak to people who use the app, not to its developers', function (): void {
    foreach (releases() as $release) {
        foreach (array_merge([$release['title']], $release['changes']) as $line) {
            foreach (['/', '.php', '.js', '()', '_'] as $mark) {
                assertFalse(str_contains($line, $mark), "{$release['version']}: \"{$mark}\" in \"{$line}\"");
            }
            assertFalse((bool)preg_match('/token|secret|password/i', $line), "{$release['version']}: a word about credentials in \"{$line}\"");
        }
    }
});
