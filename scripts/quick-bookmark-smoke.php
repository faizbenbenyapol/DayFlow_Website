<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/config.php';
require_once ROOT . '/config/database.php';
require_once ROOT . '/models/QuickItem.php';
require_once ROOT . '/models/Bookmark.php';

foreach (['quick_items', 'bookmarks'] as $table) {
    if (!(int)DB::run(
        'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
        [$table]
    )->fetchColumn()) {
        fwrite(STDERR, "Missing table: {$table}\n");
        exit(1);
    }
}

$userId = (int)DB::run('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
if ($userId > 0) {
    DB::conn()->beginTransaction();
    $quickId = QuickItem::create($userId, 'smoke quick item');
    if (!QuickItem::toggle($quickId, $userId) || !QuickItem::delete($quickId, $userId)) {
        DB::conn()->rollBack();
        exit(1);
    }
    $bookmarkId = Bookmark::create($userId, 'Smoke', 'https://example.com', 'Test');
    if (!Bookmark::delete($bookmarkId, $userId)) {
        DB::conn()->rollBack();
        exit(1);
    }
    DB::conn()->rollBack();
}

echo "Quick/bookmark smoke passed.\n";
