<?php
// =====================================================
// tests/integration/appearance_test.php
//
// The look of the app: three themes (light, dark, auto), the switch for the
// weekday colour, and the attributes the page header puts on <html> so that
// tokens.css can act on them.
// =====================================================

declare(strict_types=1);

function appearanceClient(string $label): TestClient
{
    $client = new TestClient(TEST_BASE_URL);
    $client->login('acct_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
}

/** The opening <html …> tag of a rendered page. */
function htmlTag(string $body): string
{
    preg_match('/<html\b[^>]*>/', $body, $m);
    return $m[0] ?? '';
}

test('only light, dark and auto are themes now', function (TestClient $_c): void {
    $client = appearanceClient('look_theme');

    foreach (['light', 'dark', 'auto'] as $theme) {
        $response = $client->post('/api/settings/theme', ['theme' => $theme]);
        assertSame(200, $response['status'], "{$theme} should be accepted: " . substr($response['body'], 0, 200));
    }

    // The four palettes v2 removed must not be saved again.
    foreach (['soft', 'lavender', 'ocean', 'peach', 'hacker'] as $theme) {
        assertSame(422, $client->post('/api/settings/theme', ['theme' => $theme])['status'], "{$theme} should be refused");
    }
});

test('the weekday colour can be switched off and on, and the page says so', function (TestClient $_c): void {
    $client = appearanceClient('look_day');

    assertSame(200, $client->post('/api/settings/day-color', ['enabled' => false])['status']);
    assertStringContains('data-daycolor="off"', htmlTag($client->get('/settings')['body']));

    assertSame(200, $client->post('/api/settings/day-color', ['enabled' => true])['status']);
    assertStringContains('data-daycolor="on"', htmlTag($client->get('/settings')['body']));

    // Anything that is not a plain on/off is refused rather than guessed at.
    assertSame(422, $client->post('/api/settings/day-color', ['enabled' => 'maybe'])['status']);
    assertSame(422, $client->post('/api/settings/day-color', [])['status']);
});

test('every page carries today\'s weekday for the date colour', function (TestClient $_c): void {
    $client = appearanceClient('look_week');
    $tag = htmlTag($client->get('/')['body']);

    $expected = strtolower(date('D'));
    assertStringContains('data-day="' . $expected . '"', $tag, $tag);
    assertTrue(in_array($expected, ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], true), 'tokens.css defines these seven keys');
});

test('the page loads the design tokens before the stylesheets that use them', function (TestClient $_c): void {
    $client = appearanceClient('look_asset');
    $body = $client->get('/')['body'];

    $tokens = strpos($body, '/assets/css/tokens.css');
    $components = strpos($body, '/assets/css/components.css');
    assertTrue($tokens !== false, 'tokens.css is linked');
    assertTrue($tokens < $components, 'tokens.css comes before components.css');
    assertFalse(str_contains($body, 'inter-latin'), 'Inter is gone');
    assertFalse(str_contains($body, '/assets/css/app.css'), 'app.css is gone, folded into components.css');
});

test('the demo account cannot change the weekday colour', function (TestClient $_c): void {
    $visitor = demoVisitor();
    assertSame(403, $visitor->post('/api/settings/day-color', ['enabled' => false])['status']);
});

test('the component gallery opens in development and needs a login', function (TestClient $_c): void {
    $client = appearanceClient('look_gallery');
    $page = $client->get('/dev/components');
    assertSame(200, $page['status'], 'the gallery should open while developing');
    assertStringContains('ชุด component', $page['body']);

    $anonymous = new TestClient(TEST_BASE_URL);
    assertContains($anonymous->get('/dev/components')['status'], [302, 401, 403], 'a visitor must not see it');
});
