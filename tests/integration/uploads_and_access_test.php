<?php
// =====================================================
// tests/integration/uploads_and_access_test.php
//
// The last round of review findings: uploaded files served straight off disk,
// screenshot types taken on the uploader's word, project roles accepted as any
// string, sign-up without a limit, and pages pulling third-party code or fonts
// the policy does not cover.
// =====================================================

declare(strict_types=1);

/** A 1×1 PNG. */
const TINY_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function accessClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('acc_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    $client->get('/stocks'); // a page, for a CSRF token
    return $clients[$label] = $client;
}

// =====================================================
// Uploads
// =====================================================

test('uploaded files are never served straight from disk', function (TestClient $_c): void {
    $anonymous = new TestClient(TEST_BASE_URL);

    foreach (['/uploads/', '/uploads/.htaccess', '/uploads/1/anything.html', '/uploads/stocks/1/x.png'] as $path) {
        assertNotServed($anonymous, $path);
    }
});

test('a screenshot must really be an image, whatever it claims', function (TestClient $_c): void {
    $client = accessClient('stocks');

    $response = $client->uploadMany('/api/stocks/screenshots', 'file', [
        ['name' => 'evil.html', 'contents' => '<script>alert(document.domain)</script>', 'type' => 'image/png'],
    ]);
    assertSame(422, $response['status'], 'HTML labelled as a PNG must be refused');

    $response = $client->uploadMany('/api/stocks/screenshots', 'file', [
        ['name' => 'fake.png', 'contents' => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'type' => 'image/png'],
    ]);
    assertSame(422, $response['status'], 'SVG is not an allowed screenshot type');
});

test('a screenshot is stored under its real type and served to its owner only', function (TestClient $_c): void {
    $owner = accessClient('stocks');

    // A real PNG whose name claims otherwise.
    $response = $owner->uploadMany('/api/stocks/screenshots', 'file', [
        ['name' => 'portfolio.html', 'contents' => base64_decode(TINY_PNG), 'type' => 'text/html'],
    ]);
    assertSame(201, $response['status'], 'upload failed: ' . substr($response['body'], 0, 200));
    $shot = $owner->json($response)['screenshot'];
    assertTrue(str_ends_with((string)$shot['file_path'], '.png'), 'the stored name follows the detected type');

    $image = $owner->get('/api/stocks/screenshots/' . (int)$shot['id'] . '/image');
    assertSame(200, $image['status']);
    assertTrue((bool)preg_match('~^Content-Type: image/png\s*$~mi', $image['headers']), 'served as image/png');
    assertTrue((bool)preg_match('~^X-Content-Type-Options: nosniff\s*$~mi', $image['headers']), 'with nosniff');
    assertSame(base64_decode(TINY_PNG), $image['body']);

    assertSame(404, accessClient('stranger')->get('/api/stocks/screenshots/' . (int)$shot['id'] . '/image')['status']);
    assertContains((new TestClient(TEST_BASE_URL))->get('/api/stocks/screenshots/' . (int)$shot['id'] . '/image')['status'], [401, 302]);
});

// =====================================================
// Project roles
// =====================================================

test('only Editor and Viewer can be granted on a project', function (TestClient $_c): void {
    $owner = accessClient('proj_owner');
    accessClient('proj_member'); // exists, so an invite could otherwise succeed

    $project = $owner->json($owner->post('/api/projects', [
        'name' => 'บทบาท ' . TEST_RUN_ID, 'status' => 'Planning', 'priority' => 'Medium',
    ]));
    $id = (int)($project['id'] ?? ($project['project']['id'] ?? 0));
    assertTrue($id > 0, 'project not created');

    foreach (['Owner', 'Admin', 'viewer', ''] as $role) {
        $response = $owner->post("/api/projects/{$id}/members", [
            'email_or_username' => 'acc_proj_member_' . TEST_RUN_ID, 'role' => $role,
        ]);
        assertSame(422, $response['status'], "member role '{$role}' must be refused");

        assertSame(422, $owner->post("/api/projects/{$id}/share", ['share_role' => $role])['status'], "share role '{$role}'");
    }

    assertSame(200, $owner->post("/api/projects/{$id}/members", [
        'email_or_username' => 'acc_proj_member_' . TEST_RUN_ID, 'role' => 'Viewer',
    ])['status'], 'a valid role is still accepted');
});

test('closing a public project link cuts off a guest already inside', function (TestClient $_c): void {
    $owner = accessClient('proj_owner');
    $project = $owner->json($owner->post('/api/projects', [
        'name' => 'ปิดลิงก์ ' . TEST_RUN_ID, 'status' => 'Planning', 'priority' => 'Medium',
    ]));
    $id = (int)($project['id'] ?? ($project['project']['id'] ?? 0));

    $share = $owner->json($owner->post("/api/projects/{$id}/share", ['share_role' => 'Viewer']));
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/project/shared/' . $share['share_token']);
    assertSame(200, $guest->get("/api/projects/{$id}/tasks")['status'], 'the guest is in');

    $owner->request('DELETE', "/api/projects/{$id}/share", []);
    assertSame(404, $guest->get("/api/projects/{$id}/tasks")['status'], 'the same session is out once the link is closed');
});

// =====================================================
// Sign-up, third-party code, fonts
// =====================================================

test('sign-ups from one address are limited', function (TestClient $_c): void {
    $client = new TestClient(TEST_BASE_URL);
    $client->headers = ['X-Forwarded-For: 203.0.113.' . random_int(1, 254)];
    $client->get('/login');

    $statuses = [];
    for ($i = 0; $i < 11; $i++) {
        $statuses[] = $client->post('/api/auth/register', [
            'username' => 'x', 'email' => 'bad', 'password' => 'short', 'confirm_password' => 'short',
        ])['status'];
    }

    assertSame(422, $statuses[0], 'an invalid sign-up is just invalid');
    assertSame(429, $statuses[10], 'the 11th attempt in an hour is refused');
});

test('every CDN script is pinned with an integrity hash', function (TestClient $_c): void {
    $client = accessClient('stocks');

    foreach (['/tasks', '/finance', '/file-tools', '/transfer'] as $page) {
        $body = $client->get($page)['body'];
        preg_match_all('~<script[^>]+src="https://[^"]+"[^>]*>~', $body, $tags);
        foreach ($tags[0] as $tag) {
            if (str_contains($tag, 'accounts.google.com')) continue; // Google serves it unversioned
            assertTrue(str_contains($tag, 'integrity="sha384-'), "{$page}: {$tag} has no integrity hash");
            assertTrue(str_contains($tag, 'crossorigin="anonymous"'), "{$page}: {$tag} needs crossorigin");
        }
    }
});

test('the error page uses the self-hosted fonts', function (TestClient $_c): void {
    $body = (new TestClient(TEST_BASE_URL))->get('/this-page-does-not-exist')['body'];

    assertFalse(str_contains($body, 'fonts.googleapis.com'), 'the CSP blocks Google Fonts');
    assertStringContains('/assets/css/fonts.css', $body);
});
