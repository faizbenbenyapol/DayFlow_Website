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
// modules Export/Import covers, plus the projects the demo owns. A personal
// backup leaves projects out because collaborators share them; the demo may
// not add members or make project links, so its projects are its alone.
// Files and public links are not part of it (the demo may not make links or
// upload files in the first place).
// =====================================================

final class DemoReset
{
    /** Rows that live inside a project, restored after their project. */
    private const PROJECT_CHILDREN = ['project_tasks', 'project_activities', 'project_chats'];

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
        $counts = array_map('count', array_filter($data, 'is_array'));

        $data['demo_projects'] = self::exportProjects($demoUserId);
        $counts += array_map('count', $data['demo_projects']);

        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents(self::snapshotPath(), $json, LOCK_EX) === false) {
            throw new RuntimeException('เขียนไฟล์ snapshot ไม่ได้: ' . self::snapshotPath());
        }
        @chmod(self::snapshotPath(), 0600);

        return $counts;
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

        $counts = AccountData::import($demoUserId, $data);

        // A snapshot taken before projects were covered has no such key and
        // leaves the demo's projects as they are, like any missing table.
        if (isset($data['demo_projects']) && is_array($data['demo_projects'])) {
            $counts += self::restoreProjects($demoUserId, $data['demo_projects']);
        }
        return $counts;
    }

    /**
     * The projects the demo owns and everything inside them.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private static function exportProjects(int $demoUserId): array
    {
        $out = ['projects' => DB::run('SELECT * FROM projects WHERE user_id = ?', [$demoUserId])->fetchAll()];
        foreach (self::PROJECT_CHILDREN as $table) {
            $out[$table] = DB::run(
                "SELECT c.* FROM `$table` c JOIN projects p ON p.id = c.project_id WHERE p.user_id = ?",
                [$demoUserId]
            )->fetchAll();
        }
        return $out;
    }

    /**
     * Drops every project the demo owns (their rows go with them by ON DELETE
     * CASCADE) and puts the snapshot's back under fresh ids. Projects the demo
     * was invited into belong to someone else and are left alone.
     *
     * @param array<string, mixed> $snapshot
     * @return array<string, int>
     */
    private static function restoreProjects(int $demoUserId, array $snapshot): array
    {
        $conn = DB::conn();
        $conn->beginTransaction();

        try {
            DB::run('DELETE FROM projects WHERE user_id = ?', [$demoUserId]);

            $newIds = [];
            $counts = ['projects' => 0];
            foreach ((array)($snapshot['projects'] ?? []) as $row) {
                // A visitor may not make project links, so none comes back.
                $row = array_merge(self::cleanRow('projects', $row), ['user_id' => $demoUserId, 'share_token' => null]);
                $oldId = (string)($row['id'] ?? '');
                unset($row['id']);
                self::insert('projects', $row);
                $newIds[$oldId] = (int)$conn->lastInsertId();
                $counts['projects']++;
            }

            foreach (self::PROJECT_CHILDREN as $table) {
                $counts[$table] = 0;
                foreach ((array)($snapshot[$table] ?? []) as $row) {
                    $row = self::cleanRow($table, $row);
                    $projectId = $newIds[(string)($row['project_id'] ?? '')] ?? null;
                    if ($projectId === null) continue;

                    unset($row['id']);
                    self::insert($table, array_merge($row, ['project_id' => $projectId, 'user_id' => $demoUserId]));
                    $counts[$table]++;
                }
            }

            $conn->commit();
            return $counts;
        } catch (\Throwable $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    /**
     * Keeps only real columns of $table with scalar values, so a hand-edited
     * snapshot cannot put a name into the SQL.
     *
     * @return array<string, mixed>
     */
    private static function cleanRow(string $table, mixed $row): array
    {
        if (!is_array($row)) return [];
        $row = array_intersect_key($row, AccountData::columns($table));
        return array_filter($row, fn($value): bool => $value === null || is_scalar($value));
    }

    /** @param array<string, mixed> $row */
    private static function insert(string $table, array $row): void
    {
        $names = implode('`, `', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        DB::run("INSERT INTO `$table` (`$names`) VALUES ($marks)", array_values($row));
    }
}
