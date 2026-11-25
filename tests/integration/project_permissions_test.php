<?php
// =====================================================
// tests/integration/project_permissions_test.php
//
// Projects are the only place in DayFlow with more than one person in them,
// so every endpoint is exercised from each angle: the owner, an Admin, an
// Editor member, a Viewer member, a guest on a public share link, and an
// unrelated account.
//
// The matrix below is the one place that says which minimum role each
// endpoint takes. Destructive endpoints get a fresh project for every caller
// so a successful call cannot eat the shared fixture.
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * Builds one project with the whole cast around it. Runs once per suite; the
 * returned handles are reused by the non-destructive cases below.
 *
 * @return array<string, mixed>
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

    [$owner, $ownerU]       = $account('owner');
    [$admin, $adminU]       = $account('admin');
    [$editor, $editorU]     = $account('editor');
    [$viewer, $viewerU]     = $account('viewer');
    [$outsider, $outsiderU] = $account('outsider');
    [$invitee, $inviteeU]   = $account('invitee');

    $project = $owner->json($owner->post('/api/projects', [
        'name'     => 'โครงการทดสอบสิทธิ์ ' . TEST_RUN_ID,
        'status'   => 'Planning',
        'priority' => 'Medium',
    ]));
    $projectId = (int)($project['id'] ?? ($project['project']['id'] ?? 0));
    if ($projectId < 1) throw new RuntimeException('fixture project not created: ' . json_encode($project));

    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $adminU, 'role' => 'Admin']);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $editorU, 'role' => 'Editor']);
    $owner->post("/api/projects/{$projectId}/members", ['email_or_username' => $viewerU, 'role' => 'Viewer']);

    $task = $owner->json($owner->post("/api/projects/{$projectId}/tasks", [
        'title' => 'งานตั้งต้น', 'status' => 'To Do', 'priority' => 'Medium',
    ]));
    $taskId = (int)($task['id'] ?? ($task['task']['id'] ?? 0));

    // A public link marked Viewer, opened by a browser that never signs in.
    $share = $owner->json($owner->post("/api/projects/{$projectId}/share", ['share_role' => 'Viewer']));
    $shareToken = (string)($share['share_token'] ?? '');
    $guest = new TestClient(TEST_BASE_URL);
    $guest->get('/project/shared/' . $shareToken);
    $guest->get('/projects'); // land in guest mode and pick up a CSRF token

    $members = $owner->json($owner->get("/api/projects/{$projectId}/members"));
    $idOf = static function (array $members, string $username): int {
        foreach ($members['members'] ?? [] as $m) {
            if (($m['username'] ?? '') === $username) return (int)$m['id'];
        }
        return 0;
    };

    /** @return int */
    $userId = static function (string $username): int {
        $row = DB::run('SELECT id FROM users WHERE username = ?', [$username])->fetch();
        return $row ? (int)$row['id'] : 0;
    };

    return $fixture = [
        'owner' => $owner, 'admin' => $admin, 'editor' => $editor,
        'viewer' => $viewer, 'outsider' => $outsider, 'guest' => $guest,
        'invitee' => $invitee,
        'ownerU' => $ownerU, 'adminU' => $adminU, 'editorU' => $editorU,
        'viewerU' => $viewerU, 'outsiderU' => $outsiderU, 'inviteeU' => $inviteeU,
        'projectId' => $projectId, 'taskId' => $taskId, 'shareToken' => $shareToken,
        'ownerId' => $idOf($members, $ownerU),
        'adminId' => $idOf($members, $adminU),
        'editorId' => $idOf($members, $editorU),
        'viewerId' => $idOf($members, $viewerU),
        'outsiderId' => $userId($outsiderU),
        'inviteeId' => $userId($inviteeU),
    ];
}

/**
 * Creates a project owned by the fixture owner and returns its id.
 *
 * @param array<string, mixed> $f fixture
 */
function permissionProject(array $f, string $label): int
{
    $owner = $f['owner'];
    $response = $owner->post('/api/projects', [
        'name'     => 'สิทธิ์ ' . $label . ' ' . bin2hex(random_bytes(3)) . ' ' . TEST_RUN_ID,
        'type'     => 'general',
        'status'   => 'Planning',
        'priority' => 'Medium',
    ]);
    if ($response['status'] !== 201) {
        throw new RuntimeException('permission project not created: ' . $response['body']);
    }
    return (int)($owner->json($response)['id'] ?? 0);
}

/**
 * Reads member ids of a project by username.
 *
 * @param array<string, mixed> $f fixture
 * @return array<string, int> username => user id
 */
function permissionMembers(array $f, int $projectId): array
{
    $response = $f['owner']->get("/api/projects/{$projectId}/members");
    $ids = [];
    foreach ($f['owner']->json($response)['members'] ?? [] as $member) {
        $ids[(string)($member['username'] ?? '')] = (int)$member['id'];
    }
    return $ids;
}

/**
 * Builds a fresh project with the owner, Admin, Editor and Viewer attached.
 *
 * @param array<string, mixed> $f fixture
 * @return array{projectId: int, members: array<string, int>}
 */
function permissionFreshContext(array $f, string $label): array
{
    $projectId = permissionProject($f, $label);
    $f['owner']->post("/api/projects/{$projectId}/members", ['email_or_username' => $f['adminU'], 'role' => 'Admin']);
    $f['owner']->post("/api/projects/{$projectId}/members", ['email_or_username' => $f['editorU'], 'role' => 'Editor']);
    $f['owner']->post("/api/projects/{$projectId}/members", ['email_or_username' => $f['viewerU'], 'role' => 'Viewer']);

    return ['projectId' => $projectId, 'members' => permissionMembers($f, $projectId)];
}

test('the fixture wires up an owner, an admin, an editor, a viewer and a guest', function (TestClient $_c): void {
    $f = projectFixture();

    assertTrue($f['projectId'] > 0, 'project was created');
    assertTrue($f['taskId'] > 0, 'seed task was created');
    assertTrue($f['adminId'] > 0, 'admin joined the project');
    assertTrue($f['editorId'] > 0, 'editor joined the project');
    assertTrue($f['viewerId'] > 0, 'viewer joined the project');
    assertTrue($f['shareToken'] !== '', 'the public link was opened');
});

// --- Reading ---

test('members and the share guest can read the project', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    foreach (['owner', 'admin', 'editor', 'viewer', 'guest'] as $who) {
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

test('an Editor, an Admin and the owner can post into the chat', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    foreach (['editor', 'admin', 'owner'] as $who) {
        assertSame(201, $f[$who]->post("/api/projects/{$id}/chat", ['message' => "{$who} posting"])['status'], "{$who} post");
    }
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

test('an Editor and an Admin can change tasks', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    foreach (['editor', 'admin'] as $who) {
        $created = $f[$who]->post("/api/projects/{$id}/tasks", [
            'title' => "งานจาก {$who}", 'status' => 'To Do', 'priority' => 'Medium',
        ]);
        assertSame(201, $created['status'], "{$who} must be able to add work: " . $created['body']);
    }
});

// --- Project-level mutations ---

test('an Editor cannot rename or delete the project', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

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

test('the owner and an Admin can rename the project', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    assertSame(200, $f['admin']->request('PUT', "/api/projects/{$id}", ['description' => 'ผู้ดูแลแก้ได้'])['status']);
    assertSame(200, $f['owner']->request('PUT', "/api/projects/{$id}", [
        'name' => 'โครงการทดสอบสิทธิ์ ' . TEST_RUN_ID . ' (แก้แล้ว)',
    ])['status']);
});

// --- Membership and share links ---

test('Editor and Viewer cannot invite members', function (TestClient $_c): void {
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

test('Editor and Viewer cannot open or close the public share link', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    foreach (['editor', 'viewer'] as $who) {
        assertSame(403, $f[$who]->post("/api/projects/{$id}/share", ['share_role' => 'Viewer'])['status'], "{$who} enable");
        assertSame(403, $f[$who]->request('DELETE', "/api/projects/{$id}/share", [])['status'], "{$who} disable");
    }
});

test('a share link can never be an Admin link', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];

    assertSame(422, $f['owner']->post("/api/projects/{$id}/share", ['share_role' => 'Admin'])['status']);
});

// --- The permission matrix ---

test('every endpoint answers each role as the matrix says', function (TestClient $_c): void {
    $f = projectFixture();
    $fixtureCtx = [
        'projectId' => $f['projectId'],
        'taskId'    => $f['taskId'],
        'members'   => permissionMembers($f, $f['projectId']),
    ];

    $always = ['owner' => null, 'admin' => null, 'editor' => null, 'viewer' => null, 'outsider' => null, 'guest' => null];

    $cases = [
        [
            'name' => 'task create',
            'fresh' => false,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->post("/api/projects/{$ctx['projectId']}/tasks", ['title' => "งานเมทริกซ์ {$who}", 'status' => 'To Do', 'priority' => 'Medium']),
            'expect' => ['owner' => 201, 'admin' => 201, 'editor' => 201, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'task update',
            'fresh' => false,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->request('PUT', "/api/projects/tasks/{$ctx['taskId']}", ['title' => "แก้เมทริกซ์ {$who}"]),
            'expect' => ['owner' => 200, 'admin' => 200, 'editor' => 200, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'task delete',
            'fresh' => false,
            'call' => static function (TestClient $c, array $ctx, string $who) use ($f): array {
                $created = $f['owner']->post("/api/projects/{$ctx['projectId']}/tasks", [
                    'title' => "ลบเมทริกซ์ {$who}", 'status' => 'To Do', 'priority' => 'Medium',
                ]);
                $taskId = (int)($f['owner']->json($created)['id'] ?? 0);
                return $c->request('DELETE', "/api/projects/tasks/{$taskId}", []);
            },
            'expect' => ['owner' => 200, 'admin' => 200, 'editor' => 200, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'task reorder',
            'fresh' => false,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->post('/api/projects/tasks/reorder', [
                    'project_id' => $ctx['projectId'],
                    'items' => [['id' => $ctx['taskId'], 'status' => 'To Do', 'position' => 0]],
                ]),
            'expect' => ['owner' => 200, 'admin' => 200, 'editor' => 200, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'chat send',
            'fresh' => false,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->post("/api/projects/{$ctx['projectId']}/chat", ['message' => "แชทเมทริกซ์ {$who}"]),
            'expect' => ['owner' => 201, 'admin' => 201, 'editor' => 201, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'project update',
            'fresh' => false,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->request('PUT', "/api/projects/{$ctx['projectId']}", ['priority' => 'High']),
            'expect' => ['owner' => 200, 'admin' => 200, 'editor' => 403, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'share enable',
            'fresh' => false,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->post("/api/projects/{$ctx['projectId']}/share", ['share_role' => 'Viewer']),
            'expect' => ['owner' => 200, 'admin' => 200, 'editor' => 403, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'column create',
            'fresh' => false,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->post("/api/projects/{$ctx['projectId']}/columns", ['name' => "คอลัมน์เมทริกซ์ {$who}", 'grp' => 'todo']),
            'expect' => ['owner' => 201, 'admin' => 201, 'editor' => 403, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'change project type',
            'fresh' => true,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->request('PUT', "/api/projects/{$ctx['projectId']}", ['type' => 'software']),
            'expect' => ['owner' => 200, 'admin' => 403, 'editor' => 403, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'project delete',
            'fresh' => true,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->request('DELETE', "/api/projects/{$ctx['projectId']}", []),
            'expect' => ['owner' => 200, 'admin' => 403, 'editor' => 403, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'share disable',
            'fresh' => true,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->request('DELETE', "/api/projects/{$ctx['projectId']}/share", []),
            'expect' => ['owner' => 200, 'admin' => 200, 'editor' => 403, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'member invite',
            'fresh' => true,
            'call' => static fn(TestClient $c, array $ctx, string $who): array =>
                $c->post("/api/projects/{$ctx['projectId']}/members", ['email_or_username' => $f['inviteeU'], 'role' => 'Editor']),
            'expect' => ['owner' => 200, 'admin' => 200, 'editor' => 403, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'member role change',
            'fresh' => true,
            'call' => static function (TestClient $c, array $ctx, string $who) use ($f): array {
                return $c->request('PUT', "/api/projects/{$ctx['projectId']}/members/{$ctx['members'][$f['editorU']]}", ['role' => 'Viewer']);
            },
            'expect' => ['owner' => 200, 'admin' => 200, 'editor' => 403, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
        [
            'name' => 'member remove',
            'fresh' => true,
            'call' => static function (TestClient $c, array $ctx, string $who) use ($f): array {
                // The target is never the caller: the editor ejects the viewer
                // and the viewer ejects the editor, so neither is a self-leave.
                $target = $who === 'viewer' ? $ctx['members'][$f['editorU']] : $ctx['members'][$f['viewerU']];
                return $c->request('DELETE', "/api/projects/{$ctx['projectId']}/members/{$target}", []);
            },
            'expect' => ['owner' => 200, 'admin' => 200, 'editor' => 403, 'viewer' => 403, 'outsider' => 404, 'guest' => 403],
        ],
    ];

    foreach ($cases as $case) {
        assertSame(array_keys($always), array_keys($case['expect']), $case['name'] . ' lists every role');

        foreach ($case['expect'] as $who => $expected) {
            $ctx = $case['fresh'] ? permissionFreshContext($f, $case['name'] . ' ' . $who) : $fixtureCtx;

            if ($case['fresh'] && $who === 'guest') {
                $share = $f['owner']->json($f['owner']->post("/api/projects/{$ctx['projectId']}/share", ['share_role' => 'Viewer']));
                $f['guest']->get('/project/shared/' . ($share['share_token'] ?? ''));
            }

            $response = $case['call']($f[$who], $ctx, $who);

            if ($case['fresh'] && $who === 'guest') {
                $f['guest']->get('/project/shared/' . $f['shareToken']);
            }

            assertSame($expected, $response['status'], $case['name'] . " as {$who}: " . $response['body']);
        }
    }
});

// --- Changing the project type: owner only ---

test('only the owner changes the project type and the columns stay', function (TestClient $_c): void {
    $f = projectFixture();
    $ctx = permissionFreshContext($f, 'ประเภท');
    $id = $ctx['projectId'];

    $before = $f['owner']->json($f['owner']->get("/api/projects/{$id}/tasks"))['columns'] ?? [];
    assertTrue($before !== [], 'the project has its default columns');

    assertSame(403, $f['admin']->request('PUT', "/api/projects/{$id}", ['type' => 'software'])['status'], 'an Admin may not change the type');
    assertSame(422, $f['owner']->request('PUT', "/api/projects/{$id}", ['type' => 'not-a-type'])['status'], 'an unknown type is refused');

    assertSame(200, $f['owner']->request('PUT', "/api/projects/{$id}", ['type' => 'software'])['status']);

    $detail = $f['owner']->json($f['owner']->get("/api/projects/{$id}/tasks"));
    assertSame('software', $detail['project']['type'] ?? null, 'the type changed');
    assertSame($before, $detail['columns'] ?? [], 'changing the type never touches the columns');
});

// --- Team rules ---

test('inviting as Admin is the owner alone', function (TestClient $_c): void {
    $f = projectFixture();
    $ctx = permissionFreshContext($f, 'เชิญผู้ดูแล');
    $id = $ctx['projectId'];

    assertSame(403, $f['admin']->post("/api/projects/{$id}/members", [
        'email_or_username' => $f['inviteeU'], 'role' => 'Admin',
    ])['status'], 'an Admin may not appoint another Admin');

    assertSame(200, $f['owner']->post("/api/projects/{$id}/members", [
        'email_or_username' => $f['inviteeU'], 'role' => 'Admin',
    ])['status'], 'the owner may appoint an Admin');

    $members = $f['owner']->json($f['owner']->get("/api/projects/{$id}/members"))['members'] ?? [];
    $role = null;
    foreach ($members as $member) {
        if ((int)$member['id'] === $f['inviteeId']) $role = $member['role'];
    }
    assertSame('Admin', $role, 'the invited person holds Admin');
});

test('role changes follow the owner and Admin rules', function (TestClient $_c): void {
    $f = projectFixture();
    $ctx = permissionFreshContext($f, 'เปลี่ยนบทบาท');
    $id = $ctx['projectId'];
    $editorId = $ctx['members'][$f['editorU']];
    $viewerId = $ctx['members'][$f['viewerU']];

    $roleOf = static function (int $userId) use ($f, $id): ?string {
        foreach ($f['owner']->json($f['owner']->get("/api/projects/{$id}/members"))['members'] ?? [] as $member) {
            if ((int)$member['id'] === $userId) return (string)$member['role'];
        }
        return null;
    };

    // The owner may appoint an Admin.
    assertSame(200, $f['owner']->request('PUT', "/api/projects/{$id}/members/{$editorId}", ['role' => 'Admin'])['status']);
    assertSame('Admin', $roleOf($editorId), 'the owner set Admin');

    // An Admin may move a member between Editor and Viewer.
    assertSame(200, $f['admin']->request('PUT', "/api/projects/{$id}/members/{$viewerId}", ['role' => 'Editor'])['status']);
    assertSame('Editor', $roleOf($viewerId), 'Admin moved Viewer to Editor');
    assertSame(200, $f['admin']->request('PUT', "/api/projects/{$id}/members/{$viewerId}", ['role' => 'Viewer'])['status']);
    assertSame('Viewer', $roleOf($viewerId), 'Admin moved Editor back to Viewer');

    // An Admin may not touch another Admin or promote anyone to Admin.
    assertSame(403, $f['admin']->request('PUT', "/api/projects/{$id}/members/{$editorId}", ['role' => 'Viewer'])['status'], 'Admin changes an Admin');
    assertSame(403, $f['admin']->request('PUT', "/api/projects/{$id}/members/{$viewerId}", ['role' => 'Admin'])['status'], 'Admin promotes to Admin');

    // A role outside the list is invalid.
    assertSame(422, $f['owner']->request('PUT', "/api/projects/{$id}/members/{$viewerId}", ['role' => 'Boss'])['status']);

    // The owner, an unknown id and someone from another project are one answer.
    $ownerCase = $f['owner']->request('PUT', "/api/projects/{$id}/members/{$f['ownerId']}", ['role' => 'Viewer']);
    $missingCase = $f['owner']->request('PUT', "/api/projects/{$id}/members/999999999", ['role' => 'Viewer']);
    $foreignCase = $f['owner']->request('PUT', "/api/projects/{$id}/members/{$f['outsiderId']}", ['role' => 'Viewer']);

    foreach ([$ownerCase, $missingCase, $foreignCase] as $case) {
        assertSame(404, $case['status'], $case['body']);
        assertSame('{"error":"ไม่พบสมาชิก"}', $case['body'], 'the same body for every non-member id');
    }
});

test('removing members follows the owner, Admin and leave rules', function (TestClient $_c): void {
    $f = projectFixture();
    $ctx = permissionFreshContext($f, 'เอาสมาชิกออก');
    $id = $ctx['projectId'];
    $adminId = $ctx['members'][$f['adminU']];
    $editorId = $ctx['members'][$f['editorU']];
    $viewerId = $ctx['members'][$f['viewerU']];

    // An Admin may remove a Viewer.
    assertSame(200, $f['admin']->request('DELETE', "/api/projects/{$id}/members/{$viewerId}", [])['status']);

    // Once the editor is an Admin, another Admin may not remove them.
    assertSame(200, $f['owner']->request('PUT', "/api/projects/{$id}/members/{$editorId}", ['role' => 'Admin'])['status']);
    assertSame(403, $f['admin']->request('DELETE', "/api/projects/{$id}/members/{$editorId}", [])['status']);

    // A member may leave by removing themselves, even as an Admin.
    assertSame(200, $f['admin']->request('DELETE', "/api/projects/{$id}/members/{$adminId}", [])['status']);

    // The owner can never be removed, by themselves or by an Admin.
    assertSame(403, $f['owner']->request('DELETE', "/api/projects/{$id}/members/{$f['ownerId']}", [])['status']);

    $leaveCtx = permissionFreshContext($f, 'ออกเอง');
    $leaveId = $leaveCtx['projectId'];
    assertSame(200, $f['editor']->request('DELETE', "/api/projects/{$leaveId}/members/{$leaveCtx['members'][$f['editorU']]}", [])['status'], 'a member may leave');
    assertSame(403, $f['admin']->request('DELETE', "/api/projects/{$leaveId}/members/{$f['ownerId']}", [])['status'], 'an Admin may not remove the owner');

    // An id that is not a row of this project answers the same as a missing one.
    $missing = $f['owner']->request('DELETE', "/api/projects/{$leaveId}/members/999999999", []);
    $foreign = $f['owner']->request('DELETE', "/api/projects/{$leaveId}/members/{$f['outsiderId']}", []);
    assertSame(404, $missing['status'], $missing['body']);
    assertSame($missing['status'], $foreign['status'], 'same remove status');
    assertSame($missing['body'], $foreign['body'], 'same remove body');
    assertSame('{"error":"ไม่พบสมาชิก"}', $missing['body']);
});

test('removing a member clears their assignments without touching task updated_at', function (TestClient $_c): void {
    $f = projectFixture();
    $ctx = permissionFreshContext($f, 'งานว่าง');
    $id = $ctx['projectId'];
    $editorId = $ctx['members'][$f['editorU']];

    $created = $f['owner']->post("/api/projects/{$id}/tasks", [
        'title' => 'งานที่มอบให้ editor', 'status' => 'To Do', 'priority' => 'Medium',
        'assignee_user_id' => $editorId,
    ]);
    assertSame(201, $created['status'], $created['body']);
    $taskId = (int)($f['owner']->json($created)['id'] ?? 0);

    $before = DB::run('SELECT assignee_user_id, updated_at FROM project_tasks WHERE id = ?', [$taskId])->fetch();
    assertSame($editorId, (int)$before['assignee_user_id'], 'the task is assigned before removal');

    assertSame(200, $f['admin']->request('DELETE', "/api/projects/{$id}/members/{$editorId}", [])['status']);

    $after = DB::run('SELECT assignee_user_id, updated_at FROM project_tasks WHERE id = ?', [$taskId])->fetch();
    assertSame(null, $after['assignee_user_id'], 'the assignment is cleared');
    assertSame($before['updated_at'], $after['updated_at'], 'removing a member does not change the task last-edited time');
});

test('an Admin can manage columns', function (TestClient $_c): void {
    $f = projectFixture();
    $ctx = permissionFreshContext($f, 'คอลัมน์ผู้ดูแล');
    $id = $ctx['projectId'];

    $created = $f['admin']->post("/api/projects/{$id}/columns", ['name' => 'คิวผู้ดูแล', 'grp' => 'doing']);
    assertSame(201, $created['status'], $created['body']);
    $columnId = (int)($f['admin']->json($created)['id'] ?? 0);

    assertSame(200, $f['admin']->request('PUT', "/api/projects/columns/{$columnId}", ['name' => 'คิวใหม่'])['status']);

    $columns = $f['admin']->json($f['admin']->get("/api/projects/{$id}/tasks"))['columns'] ?? [];
    $doingIds = [];
    foreach ($columns as $column) {
        if ($column['grp'] === 'doing') $doingIds[] = (int)$column['id'];
    }
    assertSame(200, $f['admin']->post("/api/projects/{$id}/columns/reorder", ['grp' => 'doing', 'ids' => array_reverse($doingIds)])['status']);

    assertSame(200, $f['admin']->request('DELETE', "/api/projects/columns/{$columnId}", [])['status']);
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

test('the member list shows names but no email addresses, to members or to share guests', function (TestClient $_c): void {
    $f = projectFixture();
    $id = $f['projectId'];
    foreach (['guest', 'viewer', 'owner'] as $who) {
        $res = $f[$who]->get("/api/projects/{$id}/members");
        assertSame(200, $res['status'], "{$who} can still list the team");
        $members = $f[$who]->json($res)['members'] ?? [];
        assertTrue(count($members) >= 4, "{$who} sees the whole team");
        assertTrue(in_array($f['editorU'], array_column($members, 'username'), true), "{$who} still sees usernames for @mentions and assigning");
        assertFalse(str_contains($res['body'], '"email"'), "{$who} gets no email field: " . substr($res['body'], 0, 200));
    }
});
