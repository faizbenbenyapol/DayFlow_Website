<?php
// =====================================================
// tests/integration/menu_layout_test.php
//
// การจัดเมนูที่ผู้ใช้ตั้งเอง (เฟส 2 REQ-8–11, EDGE-4): ชื่อหมวด ย้ายเมนูข้ามหมวด
// และหน้าตั้งค่าที่ต้องมีทุกเมนูให้เปิดปิดได้
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/AppMenus.php';

/** บัญชีใหม่ต่อ label ค่าเมนูของแต่ละ test จึงไม่ปนกัน */
function layoutClient(string $label): TestClient
{
    $client = new TestClient(TEST_BASE_URL);
    $client->login('menu_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
}

/** บันทึกเมนูแบบแสดงทุกเมนูตามลำดับตั้งต้น พร้อม layout ที่ให้มา */
function saveLayout(TestClient $client, array $layout): array
{
    return $client->post('/api/settings/menus', ['menus' => AppMenus::KEYS, 'order' => AppMenus::KEYS, 'layout' => $layout]);
}

test('every menu in the app can be turned on and off in settings', function (TestClient $_c): void {
    $page = layoutClient('every')->get('/settings')['body'];
    foreach (AppMenus::KEYS as $key) {
        assertStringContains('name="visible_menus[]" value="' . $key . '"', $page, "{$key} has no checkbox in settings");
    }
});

test('a renamed place shows its new name in the tabs, the rail and the all-menus sheet', function (TestClient $_c): void {
    $client = layoutClient('rename');
    $saved = saveLayout($client, ['names' => ['life' => 'สุขภาพ']]);
    assertSame(200, $saved['status'], substr($saved['body'], 0, 200));

    $page = $client->get('/habits')['body'];
    preg_match('#<nav class="flags".*?</nav>#s', $page, $flags);
    assertStringContains('<span>สุขภาพ</span>', $flags[0] ?? '', 'the index tab');
    assertStringContains('id="menu-place-life">สุขภาพ</h3>', $page, 'the all-menus sheet');
    assertStringContains('aria-label="สุขภาพ"', $page, 'the sub-tabs');
    assertFalse(str_contains($flags[0] ?? '', '<span>ชีวิต</span>'));

    assertSame(200, saveLayout($client, ['names' => ['life' => '   ']])['status'], 'a blank name is not an error');
    preg_match('#<nav class="flags".*?</nav>#s', $client->get('/habits')['body'], $flags);
    assertStringContains('<span>ชีวิต</span>', $flags[0] ?? '', 'and brings the default back');
});

test('a name over 30 characters is refused and nothing is saved', function (TestClient $_c): void {
    $client = layoutClient('long');
    saveLayout($client, ['names' => ['money' => 'กระเป๋า']]);
    $refused = saveLayout($client, ['names' => ['money' => str_repeat('ก', 31)]]);
    assertSame(422, $refused['status']);
    assertStringContains('30', $client->json($refused)['error'] ?? '', 'the message says how long a name may be');
    assertStringContains('<span>กระเป๋า</span>', $client->get('/finance')['body'], 'the name saved before stays');
});

test('a moved menu sits under its new place and its page takes that pastel', function (TestClient $_c): void {
    $client = layoutClient('move');
    assertSame(200, saveLayout($client, ['groups' => ['stocks' => 'vault']])['status']);

    $page = $client->get('/stocks')['body'];
    preg_match('/<html\b[^>]*>/', $page, $html);
    assertStringContains('data-place="vault"', $html[0] ?? '', 'the page is in vault now');
    preg_match('#<section class="menu-place" data-place="vault".*?</section>#s', $page, $vault);
    assertStringContains(APP_URL . '/stocks"', $vault[0] ?? '', 'stocks is listed under vault');
    preg_match('#<section class="menu-place" data-place="money".*?</section>#s', $page, $money);
    assertFalse(str_contains($money[0] ?? '', APP_URL . '/stocks"'), 'and no longer under money');
});

test('a place whose menus all moved away has no tab', function (TestClient $_c): void {
    $client = layoutClient('empty');
    saveLayout($client, ['groups' => ['finance' => 'vault', 'subscriptions' => 'vault', 'stocks' => 'vault']]);
    preg_match('#<nav class="flags".*?</nav>#s', $client->get('/')['body'], $flags);
    assertFalse(str_contains($flags[0] ?? '', 'data-place="money"'));
});
