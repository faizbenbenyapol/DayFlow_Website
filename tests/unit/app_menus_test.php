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

test('a menu sits under its own place, in the user\'s order within it', function (): void {
    $groups = AppMenus::grouped(AppMenus::ordered(['bookmarks', 'notes']), []);

    $keys = array_column($groups, 'key');
    assertSame(['today', 'work', 'life', 'money', 'vault', 'tools'], $keys);
    assertSame(AppMenus::PLACES, array_slice($keys, 0, 5), 'the five places come first, in tab order');

    $vault = array_column($groups[4]['items'], 'key');
    assertSame('bookmarks', $vault[0], 'a reordered menu moves within its place');
    assertSame('notes', $vault[1]);

    $today = array_column($groups[0]['items'], 'key');
    assertSame(['today', 'review'], $today, 'Today and Review keep their place');
});

test('a hidden menu leaves its place, and an empty place leaves with it', function (): void {
    $groups = AppMenus::grouped(AppMenus::KEYS, ['finance', 'subscriptions', 'stocks']);

    assertNotContains('money', array_column($groups, 'key'));
    $all = array_merge(...array_column($groups, 'items'));
    assertNotContains('finance', array_column($all, 'key'));
    assertContains('tasks', array_column($all, 'key'));
});

test('a path belongs to the menu it is or sits under, and to that menu\'s place', function (): void {
    assertSame('today', AppMenus::menuForPath('/'));
    assertSame('finance', AppMenus::menuForPath('/finance'));
    assertSame('notes', AppMenus::menuForPath('/notes/12'), 'a page under a menu belongs to it');
    assertSame('quick-notes', AppMenus::menuForPath('/quick-notes'), 'not mistaken for /notes');
    assertSame(null, AppMenus::menuForPath('/settings'));

    assertSame('money', AppMenus::groupForPath('/subscriptions'));
    assertSame('life', AppMenus::groupForPath('/food-notes'));
    assertSame('tools', AppMenus::groupForPath('/settings'), 'outside every menu: the neutral tools pastel');
    foreach (AppMenus::PLACES as $place) {
        assertTrue(isset(AppMenus::PLACE_ICONS[$place]), "the $place tab has an icon");
    }
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
