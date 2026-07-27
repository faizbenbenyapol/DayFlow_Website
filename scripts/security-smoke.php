<?php
// Lightweight, repeatable checks for security-sensitive helpers.
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
require_once ROOT . '/config/config.php';
require_once ROOT . '/models/AppShare.php';
require_once ROOT . '/models/NoteBlock.php';

$menus = AppShare::sanitizeMenus(['tasks', 'https://invalid.example', 'notes', 'tasks']);
if ($menus !== ['tasks', 'notes']) {
    fwrite(STDERR, "App share allowlist failed\n");
    exit(1);
}

$cipher = NoteBlock::encrypt('security smoke', 'test-password', 'test-salt');
if (NoteBlock::decrypt($cipher, 'test-password', 'test-salt') !== 'security smoke') {
    fwrite(STDERR, "Note encryption round-trip failed\n");
    exit(1);
}
if (NoteBlock::decrypt($cipher, 'wrong-password', 'test-salt') !== null) {
    fwrite(STDERR, "Wrong password was accepted\n");
    exit(1);
}

echo "Security smoke passed: share allowlist and authenticated note encryption are healthy.\n";
