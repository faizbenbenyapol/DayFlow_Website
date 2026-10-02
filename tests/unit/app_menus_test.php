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

test('every managed menu is defined once, in a known group, with a path', function (): void {
    assertSame(count(AppMenus::KEYS), count(array_unique(AppMenus::KEYS)), 'no menu is listed twice');

    foreach (AppMenus::KEYS as $key) {
        assertTrue(isset(AppMenus::MENUS[$key]), "{$key} has no entry in MENUS");
        assertTrue(isset(AppMenus::GROUPS[AppMenus::MENUS[$key]['group']]), "{$key} sits in an unknown group");
        assertTrue(str_starts_with(AppMenus::MENUS[$key]['path'], '/'), "{$key} needs a path");
    }
    foreach (['today', 'review'] as $fixed) {
        assertFalse(in_array($fixed, AppMenus::KEYS, true), "{$fixed} is always on, so it cannot be hidden or reordered");
    }
});

test('the rail shows a menu under its own group, in the user\'s order within it', function (): void {
    $groups = AppMenus::grouped(AppMenus::ordered(['bookmarks', 'notes']), []);

    $keys = array_column($groups, 'key');
    assertSame(['today', 'plan', 'notes', 'money', 'body', 'tools'], $keys);

    $notes = array_column($groups[2]['items'], 'key');
    assertSame('bookmarks', $notes[0], 'a reordered menu moves within its group');
    assertSame('notes', $notes[1]);

    $today = array_column($groups[0]['items'], 'key');
    assertSame(['today', 'review'], $today, 'Today and Review keep their place');
});

test('a hidden menu leaves the rail, and an empty group leaves with it', function (): void {
    $groups = AppMenus::grouped(AppMenus::KEYS, ['exercise', 'food-notes']);

    assertNotContains('body', array_column($groups, 'key'));
    $all = array_merge(...array_column($groups, 'items'));
    assertNotContains('exercise', array_column($all, 'key'));
    assertContains('tasks', array_column($all, 'key'));
});

test('the mobile tab bar offers two visible menus and never repeats one', function (): void {
    assertSame(['tasks', 'finance'], AppMenus::mobileTabs(null, []), 'the defaults');
    assertSame(['notes', 'habits'], AppMenus::mobileTabs(['notes', 'habits'], []), 'a saved choice');
    assertSame(['notes', 'tasks'], AppMenus::mobileTabs(['notes', 'notes'], []), 'a repeat is replaced by the first default left');
    assertSame(['notes', 'tasks'], AppMenus::mobileTabs(['notes', 'admin'], []), 'an unknown menu is skipped');
    assertSame(['finance', 'projects'], AppMenus::mobileTabs(['tasks'], ['tasks']), 'a hidden menu is skipped');
    assertSame(['tasks', 'projects'], AppMenus::mobileTabs(null, ['finance']), 'a hidden default gives way to the next visible menu');
    assertSame(['tasks', 'finance'], AppMenus::mobileTabs('tasks', AppMenus::KEYS), 'everything hidden still leaves a bar');
});
