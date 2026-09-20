<?php
// =====================================================
// tests/integration/project_permissions_test.php
//
// Projects are the only place in DayFlow with more than one person in them,
// so every endpoint is exercised from each angle: the owner, an Editor member,
// a Viewer member, a guest on a public share link, and an unrelated account.
// =====================================================

declare(strict_types=1);

/**
 * Builds one project with the whole cast around it. Runs once per suite; the
 * returned handles are reused by every case below.
 *
 * @return array{
 *   owner: TestClient, editor: TestClient, viewer: TestClient,
 *   outsider: TestClient, guest: TestClient,
 *   projectId: int, taskId: int, editorId: int, viewerId: int
 * }
 */
function projectFixture(): array
{
    static $fixture = null;
    if ($fixture !== null) return $fixture;

    $password = 'TestPass123!';
    $account = static function (string $role) use ($password): array {
        $username = 'proj_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, $password);
        return [$client, $username];
    };

    [$owner]              = $account('owner');
    [$editor, $editorU]   = $account('editor');
    [$viewer, $viewerU]   = $account('viewer');
    [$outsider]           = $account('outsider');

    $project = $owner->json($owner->post('/api/projects', [
        'name'     => 'โครงการทดสอบสิทธิ์ ' . TEST_RUN_ID,
        'status'   => 'Planning',
        'priority' => 'Medium',
    ]));
    $projectId = (int)($project['id'] ?? ($project['project']['id'] ?? 0));
    if ($projectId < 1) throw new RuntimeException('fixture project not created: ' . json_encode($project));

    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $editorU, 'role' => 'Editor']);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $viewerU, 'role' => 'Viewer']);

    $task = $owner->json($owner->post("/api/projects/{$projectId}/tasks", [
        'title' => 'งานตั้งต้น', 'status' => 'To Do', 'priority' => 'Medium',
    ]));
    $taskId = (int)($task['id'] ?? ($task['task']['id'] ?? 0));

    // A public link marked Viewer, opened by a browser that never signs in.
    $share = $owner->json($owner->post("/api/projects/{$projectId}/share", ['share_role' => 'Viewer']));
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/project/shared/' . ($share['share_token'] ?? ''));
    $guest->get('/projects'); // land in guest mode and pick up a CSRF token

    // Member ids, for the remove-member cases.
    $members = $owner->json($owner->get("/api/projects/{$projectId}/members"));
    $idOf = static function (array $members, string $username): int {
        foreach ($members['members'] ?? [] as $m) {
            if (($m['username'] ?? '') === $username) return (int)$m['id'];
        }
        return 0;
    };

    return $fixture = [
        'owner' => $owner, 'editor' => $editor, 'viewer' => $viewer,
        'outsider' => $outsider, 'guest' => $guest,
        'projectId' => $projectId, 'taskId' => $taskId,
        'editorId' => $idOf($members, $editorU), 'viewerId' => $idOf($members, $viewerU),
    ];
}

test('the fixture wires up an owner, an editor, a viewer and a guest', function (TestClient $_c): void {
    $f = projectFixture();

    assertTrue($f['projectId'] > 0, 'project was created');
    assertTrue($f['taskId'] > 0, 'seed task was created');
    assertTrue($f['editorId'] > 0, 'editor joined the project');
    assertTrue($f['viewerId'] > 0, 'viewer joined the project');
});

// --- Reading ---

test('members and the share guest can read the project', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    foreach (['owner', 'editor', 'viewer', 'guest'] as $who) {
        assertSame(200, $f[$who]->get("/api/projects/{$id}/tasks")['status'], "{$who} should read tasks");
        assertSame(200, $f[$who]->get("/api/projects/{$id}/chat")['status'], "{$who} should read chat");
    }
});

test('an unrelated account cannot read the project at all', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    foreach (['/tasks', '/chat', '/members'] as $path) {
        assertSame(404, $f['outsider']->get("/api/projects/{$id}{$path}")['status'],
            "an outsider must not read {$path}");
    }
});

// --- The gap this suite was written for ---

test('a Viewer cannot post into the project chat', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    // A read-only role that can write to the chat is the whole point of the
    // role being read-only.
    assertSame(403, $f['viewer']->post("/api/projects/{$id}/chat", ['message' => 'viewer posting'])['status']);
});

test('a guest on a Viewer share link cannot post into the chat', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    assertSame(403, $f['guest']->post("/api/projects/{$id}/chat", ['message' => 'guest posting'])['status']);
});

test('an Editor and the owner can post into the chat', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    assertSame(201, $f['editor']->post("/api/projects/{$id}/chat", ['message' => 'editor posting'])['status']);
    assertSame(201, $f['owner']->post("/api/projects/{$id}/chat", ['message' => 'owner posting'])['status']);
});

// --- Task mutations ---

test('a Viewer and a guest cannot change tasks', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];
    $taskId = $f['taskId'];

    foreach (['viewer', 'guest'] as $who) {
        assertSame(403, $f[$who]->post("/api/projects/{$id}/tasks", ['title' => 'x'])['status'], "{$who} create");
        assertSame(403, $f[$who]->request('PUT', "/api/projects/tasks/{$taskId}", ['title' => 'x'])['status'], "{$who} update");
        assertSame(403, $f[$who]->request('DELETE', "/api/projects/tasks/{$taskId}", [])['status'], "{$who} delete");
        assertSame(403, $f[$who]->post('/api/projects/tasks/reorder', [
            'project_id' => $id, 'items' => [['id' => $taskId, 'status' => 'To Do', 'position' => 0]],
        ])['status'], "{$who} reorder");
    }
});

test('an Editor can change tasks', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    $created = $f['editor']->post("/api/projects/{$id}/tasks", [
        'title' => 'งานจาก editor', 'status' => 'To Do', 'priority' => 'Medium',
    ]);
    assertSame(201, $created['status'], 'an Editor must be able to add work: ' . $created['body']);
});

// --- Project-level mutations: owner only ---

test('an Editor cannot rename or delete the project', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    // This used to answer 200 "nothing changed": the model refused the write
    // but the endpoint reported success, so the UI showed no error.
    assertSame(403, $f['editor']->request('PUT', "/api/projects/{$id}", ['name' => 'เปลี่ยนชื่อโดย editor'])['status']);
    assertSame(403, $f['editor']->request('DELETE', "/api/projects/{$id}", [])['status']);
});

test('a Viewer cannot rename or delete the project', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    assertSame(403, $f['viewer']->request('PUT', "/api/projects/{$id}", ['name' => 'เปลี่ยนชื่อโดย viewer'])['status']);
    assertSame(403, $f['viewer']->request('DELETE', "/api/projects/{$id}", [])['status']);
});

test('the project keeps its name after every refused rename', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    $list = $f['owner']->json($f['owner']->get('/api/projects'));
    $mine = array_values(array_filter($list['projects'] ?? [], static fn(array $p): bool => (int)$p['id'] === $id));

    assertTrue($mine !== [], 'the owner should still see the project');
    assertStringContains('โครงการทดสอบสิทธิ์', $mine[0]['name'], 'no refused rename may have landed');
});

test('the owner can rename the project', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    assertSame(200, $f['owner']->request('PUT', "/api/projects/{$id}", [
        'name' => 'โครงการทดสอบสิทธิ์ ' . TEST_RUN_ID . ' (แก้แล้ว)',
    ])['status']);
});

// --- Membership and share links: owner only ---

test('only the owner can invite members', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    foreach (['editor', 'viewer'] as $who) {
        assertSame(403, $f[$who]->post("/api/projects/{$id}/members", [
            'email_or_username' => 'someone_else', 'role' => 'Editor',
        ])['status'], "{$who} must not invite");
    }
});

test('a member may remove themselves but not another member', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    // The editor trying to eject the viewer is not theirs to do.
    assertSame(403, $f['editor']->request('DELETE', "/api/projects/{$id}/members/{$f['viewerId']}", [])['status']);
});

test('only the owner can open or close the public share link', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    foreach (['editor', 'viewer'] as $who) {
        assertSame(403, $f[$who]->post("/api/projects/{$id}/share", ['share_role' => 'Viewer'])['status'], "{$who} enable");
        assertSame(403, $f[$who]->request('DELETE', "/api/projects/{$id}/share", [])['status'], "{$who} disable");
    }
});

// --- Guest identity ---

test('a signed-in member cannot set a guest display name', function (TestClient $_c): void {
    $f = projectFixture();

    // Guest names belong to share-link visitors; a member is shown by their
    // own display name.
    assertSame(403, $f['editor']->post('/api/projects/guest-name', ['name' => 'แอบเปลี่ยนชื่อ'])['status']);
});

test('a share-link guest can set their display name', function (TestClient $_c): void {
    $f = projectFixture();

    $response = $f['guest']->json($f['guest']->post('/api/projects/guest-name', ['name' => 'ผู้เยี่ยมชมทดสอบ']));
    assertStringContains('ผู้เยี่ยมชมทดสอบ', (string)($response['guest_name'] ?? ''));
});

// --- Nothing is reachable without a session or a share token ---

test('an anonymous caller gets nothing', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    $anonymous = new TestClient(TEST_BASE_URL);
    assertContains($anonymous->get("/api/projects/{$id}/tasks")['status'], [401, 302, 404]);
    assertContains($anonymous->get('/api/projects')['status'], [401, 302]);
});
