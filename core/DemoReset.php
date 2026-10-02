<?php
// =====================================================
// core/DemoReset.php — puts the shared demo account back as it was
//
// Every visitor of /demo is the same account and may write to most modules,
// so over time the sample data fills with whatever strangers typed. The owner
// sets the account up the way it should look and takes a snapshot; a nightly
// job then restores that snapshot over whatever has accumulated.
//
// The snapshot is the existing account export, so it covers exactly the
// modules Export/Import covers. Projects, files and public links are not part
// of it (the demo may not make links or upload files in the first place).
// =====================================================

final class DemoReset
{
    /** Where the snapshot lives. storage/ is outside the web root. */
    public static function snapshotPath(): string
    {
        return ROOT . '/storage/demo-snapshot.json';
    }

    public static function hasSnapshot(): bool
    {
        return is_file(self::snapshotPath());
    }

    /**
     * Saves the demo account's current data as the state to return to.
     *
     * @return array<string,int> rows per table
     */
    public static function snapshot(int $demoUserId): array
    {
        $data = AccountData::export($demoUserId);

        // The account row carries the password hash and is never restored.
        unset($data['user']);

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents(self::snapshotPath(), $json, LOCK_EX) === false) {
            throw new RuntimeException('เขียนไฟล์ snapshot ไม่ได้: ' . self::snapshotPath());
        }
        @chmod(self::snapshotPath(), 0600);

        return array_map('count', array_filter($data, 'is_array'));
    }

    /**
     * Replaces the demo account's data with the snapshot.
     *
     * @return array<string,int> rows restored per table
     * @throws RuntimeException when there is no usable snapshot
     */
    public static function restore(int $demoUserId): array
    {
        if (!self::hasSnapshot()) {
            throw new RuntimeException('ยังไม่มี snapshot ของบัญชี demo (php scripts/demo-reset.php --snapshot)');
        }

        $data = json_decode((string)file_get_contents(self::snapshotPath()), true);
        if (!is_array($data)) {
            throw new RuntimeException('ไฟล์ snapshot เสียหาย');
        }

        return AccountData::import($demoUserId, $data);
    }
}
