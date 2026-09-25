<?php
// =====================================================
// tests/unit/app_menus_test.php
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/AppMenus.php';

test('a saved menu order keeps its order and gains menus added since', function (): void {
    $order = AppMenus::ordered(['stocks', 'tasks']);

    assertSame(['stocks', 'tasks'], array_slice($order, 0, 2));
    assertSame(count(AppMenus::KEYS), count($order), 'every menu is present once');
    assertContains('habits', $order, 'a menu the saved order predates still shows up');
});

test('a saved order cannot inject or repeat menus', function (): void {
    $order = AppMenus::ordered(['tasks', 'admin', 'tasks', '<script>']);

    assertSame('tasks', $order[0]);
    assertNotContains('admin', $order);
    assertNotContains('<script>', $order);
    assertSame(count(AppMenus::KEYS), count($order));
});

test('anything that is not a list falls back to the default order', function (): void {
    assertSame(AppMenus::KEYS, AppMenus::ordered(null));
    assertSame(AppMenus::KEYS, AppMenus::ordered('tasks'));
});
