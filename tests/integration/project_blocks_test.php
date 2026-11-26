<?php
// =====================================================
// tests/integration/project_blocks_test.php
//
// งานขวาง (S5): ผูก/เอาออก ความสัมพันธ์สองทิศ วงจรตรงและวงจรยาว
// งานขวางตัวเอง id ที่มองไม่เห็น รหัสผิดรูป สิทธิ์ และ FK cascade
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * ผู้ใช้สี่บทบาทของชุดนี้ (เจ้าของ สมาชิก ผู้ดู และคนนอก) สร้างครั้งเดียวต่อการรัน
 *
 * @return array<string, mixed>
 */
function blockFixture(): array
{
    static $f = null;
    if ($f !== null) return $f;

    $password = 'TestPass123!';
    $make = static function (string $role) use ($password): array {
        $username = 'blk_' . $role . '_' . TEST_RUN_ID;
        $client = new TestClient(TEST_BASE_URL);
        $client->login($username, $password);
        return [$client, $username];
    };
    [$owner, $ownerU]   = $make('owner');
    [$member, $memberU] = $make('member');
    [$viewer, $viewerU] = $make('viewer');
    [$outsider]         = $make('outsider');

    return $f = compact('owner', 'member', 'viewer', 'outsider', 'ownerU', 'memberU', 'viewerU');
}

/**
 * สร้างโปรเจคใหม่พร้อมสมาชิกและผู้ดู แล้วคืนรหัสโปรเจค
 *
 * @param array<string, mixed> $f fixture
 * @param string $label คำกำกับชื่อโปรเจค
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function blockProject(array $f, string $label): int
{
    $res = $f['owner']->post('/api/projects', ['name' => 'งานขวาง ' . $label . ' ' . TEST_RUN_ID]);
    if ($res['status'] !== 201) {
        throw new RuntimeException('project create failed: ' . $res['body']);
    }
    $projectId = (int)($f['owner']->json($res)['id'] ?? 0);

    foreach (['member' => 'Editor', 'viewer' => 'Viewer'] as $key => $role) {
        $invite = $f['owner']->post("/api/projects/{$projectId}/members", [
            'email_or_username' => $f[$key . 'U'],
            'role' => $role,
        ]);
        if ($invite['status'] !== 200) {
            throw new RuntimeException('member invite failed: ' . $invite['body']);
        }
    }
    return $projectId;
}

/**
 * สร้างงานผ่าน API แล้วคืนรหัสงาน
 *
 * @param array<string, mixed> $body ข้อมูลงาน
 * @throws RuntimeException เมื่อสร้างไม่สำเร็จ
 */
function blockTaskId(TestClient $client, int $projectId, array $body): int
{
    $res = $client->post("/api/projects/{$projectId}/tasks", $body);
    if ($res['status'] !== 201) {
        throw new RuntimeException('task create failed: ' . $res['body']);
    }
    return (int)($client->json($res)['id'] ?? 0);
}

/**
 * ผูกความสัมพันธ์ผ่าน API ของงาน tid
 *
 * @param mixed $otherId งานอีกฝั่ง (อาจเป็นค่าผิดรูปได้)
 * @return array{status: int, body: string, headers: string}
 */
function blockLink(TestClient $client, int $taskId, mixed $otherId, mixed $relation = 'waits'): array
{
    return $client->post("/api/projects/tasks/{$taskId}/blocks", [
        'other_id' => $otherId,
        'relation' => $relation,
    ]);
}

/**
 * ขอบทั้งหมดของโปรเจคจาก payload ของรายการงาน
 *
 * @return list<array<string, mixed>>
 */
function blockPayloadEdges(TestClient $client, int $projectId): array
{
    $res = $client->get("/api/projects/{$projectId}/tasks");
    return $client->json($res)['blocks'] ?? [];
}

/**
 * เลขงานที่ผู้ใช้เห็นของงานหนึ่ง (KEY-NUMBER) อ่านตรงจากฐานข้อมูล
 *
 * @param int $projectId รหัสโปรเจค
 * @param int $taskId รหัสงาน
 * @return string เช่น DF-49
 */
function blockKey(int $projectId, int $taskId): string
{
    $prefix = (string)DB::run('SELECT key_prefix FROM projects WHERE id = ?', [$projectId])->fetchColumn();
    $number = (int)DB::run('SELECT number FROM project_tasks WHERE id = ?', [$taskId])->fetchColumn();
    return $prefix . '-' . $number;
}

/**
 * จำนวนขอบที่ชี้ไปงานหนึ่ง (ใช้ยืนยันว่าไม่มีแถวใหม่หลังคำขอถูกปฏิเสธ)
 *
 * @param int $blockerId งานที่ต้องเสร็จก่อน
 * @param int $blockedId งานที่ต้องรอ
 */
function blockRowCount(int $blockerId, int $blockedId): int
{
    return (int)DB::run(
        'SELECT COUNT(*) FROM project_task_blocks WHERE blocker_id = ? AND blocked_id = ?',
        [$blockerId, $blockedId]
    )->fetchColumn();
}

test('a member links "A waits B" and repeating it changes nothing', function (TestClient $_c): void {
    $f = blockFixture();
    $p = blockProject($f, 'ผูกงาน');
    $a = blockTaskId($f['owner'], $p, ['title' => 'งาน A']);
    $b = blockTaskId($f['owner'], $p, ['title' => 'งาน B']);

    $res = blockLink($f['member'], $a, $b, 'waits');
    assertSame(201, $res['status'], $res['body']);
    $json = $f['member']->json($res);
    assertSame(1, count($json['blocks'] ?? []), 'the response carries the one edge');
    assertSame($b, (int)($json['blocks'][0]['blocker_id'] ?? 0), 'B blocks A');
    assertSame($a, (int)($json['blocks'][0]['blocked_id'] ?? 0), 'A is blocked by B');
    assertSame(1, blockRowCount($b, $a), 'the row exists as (blocker B, blocked A)');

    $edges = blockPayloadEdges($f['member'], $p);
    $found = array_values(array_filter(
        $edges,
        static fn(array $edge): bool => (int)$edge['blocker_id'] === $b && (int)$edge['blocked_id'] === $a
    ));
    assertSame(1, count($found), 'the task payload includes the edge');

    $again = blockLink($f['member'], $a, $b, 'waits');
    assertSame(201, $again['status'], $again['body']);
    assertSame(1, count($f['member']->json($again)['blocks'] ?? []), 'the same link answers with the same single edge');
    assertSame(1, blockRowCount($b, $a), 'linking twice leaves one row');
});

test('the "blocks" relation stores the reverse direction', function (TestClient $_c): void {
    $f = blockFixture();
    $p = blockProject($f, 'ทิศกลับ');
    $a = blockTaskId($f['owner'], $p, ['title' => 'งาน A']);
    $b = blockTaskId($f['owner'], $p, ['title' => 'งาน B']);

    $res = blockLink($f['member'], $a, $b, 'blocks');
    assertSame(201, $res['status'], $res['body']);
    assertSame(1, blockRowCount($a, $b), 'A blocks B is stored as (blocker A, blocked B)');
    assertSame(0, blockRowCount($b, $a), 'nothing is stored in the other direction');
});

test('a direct cycle is refused with both keys and no new row', function (TestClient $_c): void {
    $f = blockFixture();
    $p = blockProject($f, 'วงจรตรง');
    $a = blockTaskId($f['owner'], $p, ['title' => 'งาน A']);
    $b = blockTaskId($f['owner'], $p, ['title' => 'งาน B']);

    assertSame(201, blockLink($f['member'], $a, $b, 'waits')['status'], 'A waits B');

    $reverse = blockLink($f['member'], $b, $a, 'waits');
    assertSame(422, $reverse['status'], $reverse['body']);
    assertStringContains(blockKey($p, $a), $reverse['body']);
    assertStringContains(blockKey($p, $b), $reverse['body']);
    assertStringContains('ไม่มีวันเริ่ม', $reverse['body']);
    assertSame(0, blockRowCount($a, $b), 'B waits A created no row');
    assertSame(1, blockRowCount($b, $a), 'only the first edge remains');
});

test('a long cycle is refused but a non-cycle link in the same chain is accepted', function (TestClient $_c): void {
    $f = blockFixture();
    $p = blockProject($f, 'วงจรยาว');
    $a = blockTaskId($f['owner'], $p, ['title' => 'งาน A']);
    $b = blockTaskId($f['owner'], $p, ['title' => 'งาน B']);
    $c = blockTaskId($f['owner'], $p, ['title' => 'งาน C']);

    assertSame(201, blockLink($f['member'], $a, $b, 'waits')['status'], 'A waits B');
    assertSame(201, blockLink($f['member'], $b, $c, 'waits')['status'], 'B waits C');

    $cycle = blockLink($f['member'], $c, $a, 'waits');
    assertSame(422, $cycle['status'], $cycle['body']);
    assertStringContains('ผ่าน 1 งาน', $cycle['body']);
    assertSame(0, blockRowCount($a, $c), 'C waits A created no row');

    $fine = blockLink($f['member'], $a, $c, 'waits');
    assertSame(201, $fine['status'], $fine['body']);
    assertSame(1, blockRowCount($c, $a), 'A waits C is stored');
});

test('a task cannot block itself', function (TestClient $_c): void {
    $f = blockFixture();
    $p = blockProject($f, 'ตัวเอง');
    $a = blockTaskId($f['owner'], $p, ['title' => 'งาน A']);

    $res = blockLink($f['member'], $a, $a, 'waits');
    assertSame(422, $res['status'], $res['body']);
    assertStringContains('งานขวางตัวเองไม่ได้', $res['body']);
    assertSame(0, (int)DB::run(
        'SELECT COUNT(*) FROM project_task_blocks b
         JOIN project_tasks t ON t.id = b.blocker_id
         WHERE t.project_id = ?',
        [$p]
    )->fetchColumn(), 'nothing was stored');
});

test('a foreign, deleted and nonexistent other task all answer the same 404', function (TestClient $_c): void {
    $f = blockFixture();
    $p = blockProject($f, 'งานที่มองไม่เห็น');
    $a = blockTaskId($f['owner'], $p, ['title' => 'งาน A']);

    $other = blockProject($f, 'โปรเจคอื่น');
    $foreign = blockTaskId($f['owner'], $other, ['title' => 'งานนอกโปรเจค']);

    $deleted = blockTaskId($f['owner'], $p, ['title' => 'งานที่จะถูกลบ']);
    assertSame(200, $f['owner']->request('DELETE', "/api/projects/tasks/{$deleted}", [])['status']);

    $foreignRes = blockLink($f['member'], $a, $foreign, 'waits');
    $deletedRes = blockLink($f['member'], $a, $deleted, 'waits');
    $missingRes = blockLink($f['member'], $a, 999999999, 'waits');

    foreach ([$foreignRes, $deletedRes, $missingRes] as $res) {
        assertSame(404, $res['status'], $res['body']);
    }
    assertSame($foreignRes['body'], $deletedRes['body'], 'a deleted id looks like a foreign id');
    assertSame($foreignRes['body'], $missingRes['body'], 'and like a nonexistent id');
});

test('malformed other ids and an unknown relation are refused with 422', function (TestClient $_c): void {
    $f = blockFixture();
    $p = blockProject($f, 'รหัสผิดรูป');
    $a = blockTaskId($f['owner'], $p, ['title' => 'งาน A']);
    $b = blockTaskId($f['owner'], $p, ['title' => 'งาน B']);

    foreach ([[null], 'x', 0, -1, 1.5, ['a' => 1]] as $bad) {
        // ค่า null ถูกส่งเป็นอาเรย์ [null] เพื่อคงคีย์ other_id ไว้ใน JSON
        $value = $bad === [null] ? null : $bad;
        $res = blockLink($f['member'], $a, $value, 'waits');
        assertSame(422, $res['status'], 'other_id ' . json_encode($value) . ' → ' . $res['body']);
        assertStringContains('ข้อมูลงานไม่ถูกต้อง', $res['body']);
    }

    foreach ([['other_id' => $b, 'relation' => 'x'], ['other_id' => $b]] as $body) {
        $res = $f['member']->post("/api/projects/tasks/{$a}/blocks", $body);
        assertSame(422, $res['status'], $res['body']);
        assertStringContains('ความสัมพันธ์ไม่ถูกต้อง', $res['body']);
    }
});

test('a viewer cannot link and an outsider sees the same 404 as a missing task', function (TestClient $_c): void {
    $f = blockFixture();
    $p = blockProject($f, 'สิทธิ์');
    $a = blockTaskId($f['owner'], $p, ['title' => 'งาน A']);
    $b = blockTaskId($f['owner'], $p, ['title' => 'งาน B']);

    $viewer = blockLink($f['viewer'], $a, $b, 'waits');
    assertSame(403, $viewer['status'], $viewer['body']);
    assertSame(0, blockRowCount($b, $a), 'the viewer stored nothing');

    $outsider = blockLink($f['outsider'], $a, $b, 'waits');
    $missing = blockLink($f['outsider'], 999999999, $b, 'waits');
    assertSame(404, $outsider['status'], $outsider['body']);
    assertSame($outsider['status'], $missing['status'], 'an outsider and a missing task answer the same');
    assertSame($outsider['body'], $missing['body'], 'without revealing which task exists');
});

test('DELETE removes the row from either end and a deleted task takes its rows with it', function (TestClient $_c): void {
    $f = blockFixture();
    $p = blockProject($f, 'เอาออก');
    $a = blockTaskId($f['owner'], $p, ['title' => 'งาน A']);
    $b = blockTaskId($f['owner'], $p, ['title' => 'งาน B']);

    assertSame(201, blockLink($f['member'], $a, $b, 'waits')['status'], 'A waits B');

    $fromBlocked = $f['member']->request('DELETE', "/api/projects/tasks/{$a}/blocks/{$b}", []);
    assertSame(200, $fromBlocked['status'], $fromBlocked['body']);
    assertSame(0, blockRowCount($b, $a), 'deleting from the blocked task removes the edge');
    assertSame([], $f['member']->json($fromBlocked)['blocks'] ?? [], 'the response has no edges left');

    assertSame(201, blockLink($f['member'], $a, $b, 'waits')['status'], 'link again');
    $fromBlocker = $f['member']->request('DELETE', "/api/projects/tasks/{$b}/blocks/{$a}", []);
    assertSame(200, $fromBlocker['status'], $fromBlocker['body']);
    assertSame(0, blockRowCount($b, $a), 'deleting from the blocker removes the same edge');

    $missing = $f['member']->request('DELETE', "/api/projects/tasks/{$a}/blocks/{$b}", []);
    assertSame(200, $missing['status'], $missing['body']);
    assertSame([], $f['member']->json($missing)['blocks'] ?? [], 'removing a link that is gone is not an error');

    assertSame(201, blockLink($f['member'], $a, $b, 'waits')['status'], 'link once more');
    assertSame(200, $f['owner']->request('DELETE', "/api/projects/tasks/{$a}", [])['status'], 'delete task A');
    assertSame(0, blockRowCount($b, $a), 'the FK cascade removed the edge');
    assertSame(0, (int)DB::run(
        'SELECT COUNT(*) FROM project_task_blocks WHERE blocker_id = ? OR blocked_id = ?',
        [$a, $a]
    )->fetchColumn(), 'no edge still points at the deleted task');
});
