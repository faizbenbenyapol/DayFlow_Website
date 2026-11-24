<?php
// =====================================================
// tests/integration/project_task_detail_test.php
//
// แผงรายละเอียดงาน (P0b slice 3): คำอธิบายงาน และแท็บประวัติของงาน
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';

/**
 * เจ้าของกับคนนอก และโปรเจคหนึ่งโปรเจคที่มีงานหนึ่งงาน
 *
 * @return array{owner: TestClient, outsider: TestClient, projectId: int, taskId: int}
 */
function detailFixture(): array
{
    static $fixture = null;
    if ($fixture !== null) return $fixture;

    $owner = new TestClient(TEST_BASE_URL);
    $owner->login('dtl_owner_' . TEST_RUN_ID, 'TestPass123!');
    $outsider = new TestClient(TEST_BASE_URL);
    $outsider->login('dtl_out_' . TEST_RUN_ID, 'TestPass123!');

    $projectId = (int)($owner->json($owner->post('/api/projects', ['name' => 'รายละเอียดงาน ' . TEST_RUN_ID]))['id'] ?? 0);
    $taskId = (int)($owner->json($owner->post("/api/projects/{$projectId}/tasks", ['title' => 'งานมีคำอธิบาย']))['id'] ?? 0);
    return $fixture = compact('owner', 'outsider', 'projectId', 'taskId');
}

test('a task keeps its description and the history says it changed without copying the text', function (TestClient $_c): void {
    $f = detailFixture();
    $text = "ผู้ใช้ส่งออกเป็น CSV\nเปิดใน Excel ภาษาไทยไม่เพี้ยน";
    assertSame(200, $f['owner']->request('PUT', '/api/projects/tasks/' . $f['taskId'], ['description' => $text])['status']);

    $tasks = $f['owner']->json($f['owner']->get("/api/projects/{$f['projectId']}/tasks"))['tasks'];
    $task = array_values(array_filter($tasks, fn(array $t): bool => (int)$t['id'] === $f['taskId']))[0];
    assertSame($text, $task['description'], 'the description comes back as written, line breaks included');

    $history = $f['owner']->json($f['owner']->get('/api/projects/tasks/' . $f['taskId'] . '/history'))['items'];
    $actions = array_column($history, 'action');
    assertTrue(count(array_filter($actions, fn(string $a): bool => str_contains($a, 'แก้คำอธิบาย'))) === 1, 'the edit is in the task history once');
    assertTrue(count(array_filter($actions, fn(string $a): bool => str_contains($a, 'เพิ่มงานย่อยใหม่'))) === 1, 'so is its creation');
    foreach ($actions as $action) {
        assertFalse(str_contains($action, 'Excel'), 'the description text is not copied into the history');
    }

    // ประวัติเขียนเป็นภาษาไทยทั้งชื่อช่องและค่า
    $f['owner']->request('PUT', '/api/projects/tasks/' . $f['taskId'], ['priority' => 'High']);
    $latest = $f['owner']->json($f['owner']->get('/api/projects/tasks/' . $f['taskId'] . '/history'))['items'][0]['action'];
    assertStringContains("ความสำคัญ เปลี่ยนเป็น 'สูง'", $latest);

    $long = str_repeat('ก', 10001);
    assertSame(422, $f['owner']->request('PUT', '/api/projects/tasks/' . $f['taskId'], ['description' => $long])['status'], 'over 10,000 characters is refused');
});

test('task history is shown to the project only; others get the same 404 as a missing task', function (TestClient $_c): void {
    $f = detailFixture();
    $foreign = $f['outsider']->get('/api/projects/tasks/' . $f['taskId'] . '/history');
    $missing = $f['owner']->get('/api/projects/tasks/999999999/history');
    assertSame(404, $foreign['status'], 'an outsider cannot read the history');
    assertSame($missing['status'], $foreign['status']);
    assertSame($missing['body'], $foreign['body'], 'same body, so the id does not leak');
});
