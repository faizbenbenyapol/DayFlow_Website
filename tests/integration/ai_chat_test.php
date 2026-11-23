<?php
// =====================================================
// tests/integration/ai_chat_test.php — API แชทสั่งงาน (เฟส 5 ชิ้น 2)
//
// ยิงผ่าน /api/ai/chat จริง โดยตั้งคีย์ custom ให้ชี้ AI ปลอม (tests/fake-llm)
// แล้วตรวจทั้งคำตอบ ข้อมูลใน DB ผ่าน API ปกติ และข้อความผลที่ป้อนกลับให้ AI
// =====================================================

declare(strict_types=1);

require_once ROOT . '/config/database.php';
require_once __DIR__ . '/../fake-llm/helpers.php';

/** บัญชีแยกของชุดทดสอบนี้ (แชทไม่เก็บประวัติ จึงใช้ซ้ำได้) */
function aiChatClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];

    $client = new TestClient(TEST_BASE_URL);
    $client->login('aic_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $clients[$label] = $client;
}

/** บันทึกผู้ให้บริการ custom ที่ชี้ AI ปลอม */
function aiChatSaveKey(TestClient $client, string $apiKey = 'sk-fake-chat-key'): void
{
    $response = $client->post('/api/ai/keys', [
        'provider' => 'custom',
        'api_key'  => $apiKey,
        'base_url' => FAKE_LLM_URL . '/v1',
        'model'    => 'fake-model',
    ]);
    assertSame(200, $response['status'], 'the fake custom endpoint should be saved');
}

/** ล้างคิวเดิมแล้วใส่คำตอบที่จะให้ AI ปลอมพูดตามลำดับ */
function aiChatQueue(array $replies): void
{
    fakeLlmReset();
    fakeLlmQueue($replies);
}

/** JSON ของคำตอบปลอม (ภาษาไทยไม่ต้อง escape) */
function aiChatJson(array $data): string
{
    return (string)json_encode($data, JSON_UNESCAPED_UNICODE);
}

/** ส่งข้อความเดียวไปที่ /api/ai/chat */
function aiChatSend(TestClient $client, string $text, string $provider = 'custom'): array
{
    return $client->post('/api/ai/chat', [
        'provider' => $provider,
        'messages' => [['role' => 'user', 'content' => $text]],
    ]);
}

/** งานทุกใบของบัญชีนี้ รวมทุก quadrant */
function aiChatTasks(TestClient $client): array
{
    $out = [];
    foreach (($client->json($client->get('/api/tasks'))['quadrants'] ?? []) as $rows) {
        foreach ($rows as $task) $out[] = $task;
    }
    return $out;
}

/** งานใบแรกที่ชื่อตรง หรือ null */
function aiChatTaskByTitle(TestClient $client, string $title): ?array
{
    foreach (aiChatTasks($client) as $task) {
        if (($task['title'] ?? null) === $title) return $task;
    }
    return null;
}

/** งานใบแรกที่ id ตรง หรือ null */
function aiChatTaskById(TestClient $client, int $id): ?array
{
    foreach (aiChatTasks($client) as $task) {
        if ((int)($task['id'] ?? 0) === $id) return $task;
    }
    return null;
}

/** สร้างงานทาง API ปกติแล้วคืนแถวงาน */
function aiChatCreateTask(TestClient $client, string $title, array $extra = []): array
{
    $response = $client->post('/api/tasks', $extra + [
        'title'    => $title,
        'quadrant' => 1,
        'due_date' => date('Y-m-d'),
    ]);
    if ($response['status'] !== 201) {
        throw new RuntimeException("task rejected ({$response['status']}): " . $response['body']);
    }
    return $client->json($response)['task'];
}

/** ข้อความ "ผลของคำสั่ง ..." ที่ AiChat ป้อนกลับไปในคำขอหนึ่งของ AI ปลอม */
function aiChatResultOf(array $request): string
{
    $messages = is_array($request['messages'] ?? null) ? $request['messages'] : [];
    foreach (array_reverse($messages) as $message) {
        $content = is_array($message) ? (string)($message['content'] ?? '') : '';
        if (str_starts_with($content, 'ผลของคำสั่ง ')) return $content;
    }
    return '';
}

// --- REQ-25: สั่งเพิ่มงานแล้วงานขึ้นในวันจริง ---

test('REQ-25: the AI adds a task for tomorrow and the task API shows it', function (TestClient $_c): void {
    $client   = aiChatClient('add');
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    aiChatSaveKey($client);

    aiChatQueue([
        aiChatJson(['command' => 'add_task', 'args' => ['title' => 'โทรหาแม่', 'due_date' => $tomorrow]]),
        aiChatJson(['reply' => 'เพิ่มแล้ว']),
    ]);

    $response = aiChatSend($client, 'เพิ่มงานโทรหาแม่พรุ่งนี้');
    assertSame(200, $response['status']);
    $body = $client->json($response);
    assertSame('เพิ่มแล้ว', $body['reply'] ?? null);
    assertTrue(array_key_exists('pending', $body), 'the response should always carry pending');
    assertSame(null, $body['pending'], 'a finished turn has no pending command');

    $task = aiChatTaskByTitle($client, 'โทรหาแม่');
    assertTrue($task !== null, 'the task should exist for this account');
    assertSame($tomorrow, $task['due_date'] ?? null);
});

// --- หลายขั้น: list แล้วใช้ id จริง ---

test('the AI chains list_tasks into toggle_task with the id from the result', function (TestClient $_c): void {
    $client = aiChatClient('chain');
    aiChatSaveKey($client);
    $task = aiChatCreateTask($client, 'งานหลายขั้น ' . TEST_RUN_ID);
    $id   = (int)$task['id'];

    aiChatQueue([
        aiChatJson(['command' => 'list_tasks', 'args' => ['date' => date('Y-m-d')]]),
        aiChatJson(['command' => 'toggle_task', 'args' => ['id' => $id]]),
        aiChatJson(['reply' => 'ปิดงานแล้ว']),
    ]);

    $response = aiChatSend($client, 'ปิดงานหลายขั้นให้หน่อย');
    assertSame(200, $response['status']);
    assertSame('ปิดงานแล้ว', $client->json($response)['reply'] ?? null);

    $stored = aiChatTaskById($client, $id);
    assertTrue($stored !== null, 'the task should still be listed');
    assertSame('done', $stored['status'], 'toggle_task should mark the task done');
    $completedAt = DB::run('SELECT completed_at FROM tasks WHERE id = ?', [$id])->fetchColumn();
    assertTrue($completedAt !== null, 'completed_at should be set when the task is ticked');

    $sent = fakeLlmRequests();
    assertSame(3, count($sent), 'list, toggle, then the final reply');
    assertStringContains('#' . $id . ' ', (string)json_encode($sent[1], JSON_UNESCAPED_UNICODE),
        'the second request should carry the list result with the real id');

    // ติ๊กซ้ำต้องเปิดงานกลับมาและล้าง completed_at
    aiChatQueue([
        aiChatJson(['command' => 'toggle_task', 'args' => ['id' => $id]]),
        aiChatJson(['reply' => 'เปิดงานแล้ว']),
    ]);
    assertSame(200, aiChatSend($client, 'เปิดงานนี้อีกครั้ง')['status']);
    assertSame(null, DB::run('SELECT completed_at FROM tasks WHERE id = ?', [$id])->fetchColumn(),
        'completed_at should be cleared when the task is reopened');
});

// --- งานทำซ้ำ: ติ๊กผ่าน AI แล้วรอบถัดไปเกิด ---

test('a daily task ticked through the chat schedules its next occurrence', function (TestClient $_c): void {
    $client = aiChatClient('repeat');
    aiChatSaveKey($client);
    $task = aiChatCreateTask($client, 'งานรายวัน ' . TEST_RUN_ID, [
        'due_date'    => date('Y-m-d'),
        'repeat_rule' => 'daily',
    ]);
    $id = (int)$task['id'];

    aiChatQueue([
        aiChatJson(['command' => 'toggle_task', 'args' => ['id' => $id]]),
        aiChatJson(['reply' => 'เสร็จแล้ว']),
    ]);
    assertSame(200, aiChatSend($client, 'ติ๊กงานรายวันให้หน่อย')['status']);

    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $next = null;
    foreach (aiChatTasks($client) as $row) {
        if (($row['title'] ?? '') !== 'งานรายวัน ' . TEST_RUN_ID) continue;
        if ((int)$row['id'] === $id) {
            assertSame('done', $row['status'], 'the occurrence that was ticked stays as history');
        } elseif ($row['status'] === 'open' && ($row['due_date'] ?? null) === $tomorrow) {
            $next = $row;
        }
    }
    assertTrue($next !== null, 'a new open occurrence should be due tomorrow');
});

// --- REQ-28: ลบต้องยืนยันก่อน ---

test('REQ-28: delete_task previews first and only confirms when asked', function (TestClient $_c): void {
    $client = aiChatClient('delete');
    aiChatSaveKey($client);
    $title = 'งานที่จะลบ ' . TEST_RUN_ID;
    $task  = aiChatCreateTask($client, $title);
    $id    = (int)$task['id'];

    aiChatQueue([aiChatJson(['command' => 'delete_task', 'args' => ['id' => $id]])]);
    $response = aiChatSend($client, 'ลบงานนี้ให้หน่อย');
    assertSame(200, $response['status']);
    $body    = $client->json($response);
    $pending = $body['pending'] ?? null;
    assertTrue(is_array($pending), 'the command must wait for confirmation');
    assertSame('delete_task', $pending['command'] ?? null);
    assertStringContains($title, (string)($body['reply'] ?? ''), 'the question should name the task');
    assertTrue(aiChatTaskById($client, $id) !== null, 'nothing is deleted before the user confirms');

    $confirmed = $client->post('/api/ai/chat/confirm', ['command' => 'delete_task', 'args' => $pending['args'] ?? []]);
    assertSame(200, $confirmed['status']);
    assertStringContains($title, (string)($client->json($confirmed)['reply'] ?? ''));
    assertSame(null, aiChatTaskById($client, $id), 'confirming should delete the task');
});

// --- EDGE-15: อะไรที่ไม่ใช่คำสั่งในทะเบียนต้องไม่ทำอะไรเลย ---

test('EDGE-15: junk replies, unknown commands and bad args change nothing', function (TestClient $_c): void {
    $client = aiChatClient('junk');
    aiChatSaveKey($client);
    $before = count(aiChatTasks($client));

    $junk = [
        'ไม่ใช่ json',
        aiChatJson(['command' => 'drop_everything', 'args' => []]),
        aiChatJson(['command' => 'add_task', 'args' => 'x']),
    ];
    foreach ($junk as $reply) {
        aiChatQueue([$reply]);
        $response = aiChatSend($client, 'ลองสั่งอะไรแปลก ๆ');
        assertSame(200, $response['status']);
        assertSame('AI ทำคำสั่งนี้ไม่ได้', $client->json($response)['reply'] ?? null);
        assertSame($before, count(aiChatTasks($client)), 'a refused command must not write anything');
    }

    // add_task ที่ไม่มี title ต้องไม่สร้างงาน
    aiChatQueue([aiChatJson(['command' => 'add_task', 'args' => []])]);
    assertSame(200, aiChatSend($client, 'เพิ่มงานไม่มีชื่อ')['status']);
    assertSame($before, count(aiChatTasks($client)), 'a missing title must not create a task');
});

// --- AI จริงตอบสรุปเป็นข้อความธรรมดาหลังรันคำสั่ง ต้องบอกตามจริงว่าทำไปแล้ว ---

test('a plain-text summary after a successful command reports what was already done', function (TestClient $_c): void {
    $client = aiChatClient('plain');
    aiChatSaveKey($client);
    $title = 'งานที่ทำไปแล้ว ' . TEST_RUN_ID;

    // ยังไม่มีคำสั่งไหนรันสำเร็จ ต้องบอกเหมือนเดิม
    aiChatQueue(['ไม่ใช่ json']);
    $refused = aiChatSend($client, 'ลองคำสั่งแปลก ๆ');
    assertSame(200, $refused['status']);
    assertSame('AI ทำคำสั่งนี้ไม่ได้', $client->json($refused)['reply'] ?? null,
        'a malformed answer with nothing run stays a refusal');

    aiChatQueue([
        aiChatJson(['command' => 'add_task', 'args' => ['title' => $title, 'due_date' => '']]),
        'เพิ่มให้แล้วครับ',
    ]);
    $response = aiChatSend($client, 'เพิ่มงานให้หน่อย');
    assertSame(200, $response['status']);
    $body  = $client->json($response);
    $reply = (string)($body['reply'] ?? '');
    assertTrue(str_starts_with($reply, 'ทำให้แล้ว:'), 'the reply should admit the command already ran');
    assertStringContains($title, $reply, 'the reply should name the task that was added');
    assertTrue(array_key_exists('pending', $body), 'the response should always carry pending');
    assertSame(null, $body['pending'], 'a finished turn has no pending command');

    $matches = array_filter(aiChatTasks($client), function (array $task) use ($title): bool {
        return ($task['title'] ?? null) === $title;
    });
    assertSame(1, count($matches), 'the task should be stored exactly once');
});

// --- ประวัติ assistant จากหน้าเว็บต้องถูกส่งให้ AI เป็น JSON และผลคำสั่งต้องย้ำให้ตอบ JSON ---

test('assistant history is sent as JSON and command results ask for JSON', function (TestClient $_c): void {
    $client = aiChatClient('history');
    aiChatSaveKey($client);

    aiChatQueue([
        aiChatJson(['command' => 'list_tasks', 'args' => ['date' => date('Y-m-d')]]),
        aiChatJson(['reply' => 'เรียบร้อย']),
    ]);
    $response = $client->post('/api/ai/chat', [
        'provider' => 'custom',
        'messages' => [
            ['role' => 'user', 'content' => 'สวัสดี'],
            ['role' => 'assistant', 'content' => 'สวัสดีครับ'],
            ['role' => 'user', 'content' => 'วันนี้มีงานอะไรบ้าง'],
        ],
    ]);
    assertSame(200, $response['status']);

    $sent = fakeLlmRequests();
    assertSame(2, count($sent), 'list_tasks then the final reply');
    $last  = $sent[1] ?? [];
    $found = false;
    foreach (($last['messages'] ?? []) as $message) {
        if (($message['role'] ?? null) === 'assistant' && ($message['content'] ?? null) === '{"reply":"สวัสดีครับ"}') {
            $found = true;
        }
    }
    assertTrue($found, 'the assistant message from the page should be wrapped as a JSON reply');
    assertStringContains('ตอบต่อเป็น JSON', aiChatResultOf($last),
        'the command result should tell the AI to answer as JSON');
});

// --- EDGE-16: id ของคนอื่นต้องเหมือน id ที่ไม่มี ---

test('EDGE-16: another account\'s ids answer exactly like missing ids', function (TestClient $_c): void {
    $owner  = aiChatClient('owner');
    $other  = aiChatClient('other');
    aiChatSaveKey($owner);
    aiChatSaveKey($other);
    $task = aiChatCreateTask($owner, 'งานของเจ้าของ ' . TEST_RUN_ID);
    $id   = (int)$task['id'];

    // toggle: ผลที่ป้อนกลับให้ AI ต้องเหมือนกัน
    aiChatQueue([aiChatJson(['command' => 'toggle_task', 'args' => ['id' => $id]])]);
    aiChatSend($other, 'ติ๊กงานนี้');
    $sent = fakeLlmRequests();
    assertSame(2, count($sent));
    $foreign = aiChatResultOf($sent[1]);

    aiChatQueue([aiChatJson(['command' => 'toggle_task', 'args' => ['id' => 999999999]])]);
    aiChatSend($other, 'ติ๊กงานนี้');
    $missing = aiChatResultOf(fakeLlmRequests()[1]);

    assertSame($missing, $foreign, 'a foreign id must be indistinguishable from a missing one');
    assertStringContains('ไม่พบงานนี้', $foreign);
    $ownerTask = aiChatTaskById($owner, $id);
    assertTrue($ownerTask !== null, 'the owner\'s task must still exist');
    assertSame('open', $ownerTask['status'], 'the owner\'s task must not change');

    // delete preview: ข้อความถามยืนยันต้องเหมือนกัน
    aiChatQueue([aiChatJson(['command' => 'delete_task', 'args' => ['id' => $id]])]);
    aiChatSend($other, 'ลบงานนี้');
    $foreignPreview = aiChatResultOf(fakeLlmRequests()[1]);

    aiChatQueue([aiChatJson(['command' => 'delete_task', 'args' => ['id' => 999999999]])]);
    aiChatSend($other, 'ลบงานนี้');
    $missingPreview = aiChatResultOf(fakeLlmRequests()[1]);

    assertSame($missingPreview, $foreignPreview, 'a foreign id must fail the preview the same way');

    // confirm: status และ body ต้องเหมือนกัน
    $foreignConfirm = $other->post('/api/ai/chat/confirm', ['command' => 'delete_task', 'args' => ['id' => $id]]);
    $missingConfirm = $other->post('/api/ai/chat/confirm', ['command' => 'delete_task', 'args' => ['id' => 999999999]]);
    assertSame(422, $foreignConfirm['status']);
    assertSame($missingConfirm['status'], $foreignConfirm['status']);
    assertSame($missingConfirm['body'], $foreignConfirm['body']);
    assertTrue(aiChatTaskById($owner, $id) !== null, 'the owner\'s task must survive');
});

// --- นิสัยและการเงิน ---

test('habits tick through the chat and list in the habit API', function (TestClient $_c): void {
    $client = aiChatClient('habit');
    aiChatSaveKey($client);

    $created = $client->post('/api/habits', [
        'name' => 'ดื่มน้ำ ' . TEST_RUN_ID, 'color' => '#123456', 'target_days' => 7,
    ]);
    assertSame(201, $created['status']);
    $id = (int)$client->json($created)['id'];

    aiChatQueue([
        aiChatJson(['command' => 'toggle_habit', 'args' => ['id' => $id]]),
        aiChatJson(['reply' => 'ติ๊กให้แล้ว']),
    ]);
    assertSame(200, aiChatSend($client, 'ทำนิสัยดื่มน้ำให้หน่อย')['status']);

    $found = null;
    foreach (($client->json($client->get('/api/habits'))['habits'] ?? []) as $habit) {
        if ((int)$habit['id'] === $id) $found = $habit;
    }
    assertTrue($found !== null, 'the habit should still be listed');
    assertSame(1, (int)($found['completed_today'] ?? 0), 'toggle_habit should tick today');
});

test('add_finance matches a category by exact name, diacritics included', function (TestClient $_c): void {
    $client = aiChatClient('finance');
    aiChatSaveKey($client);

    $category = $client->post('/api/finance/categories', ['name' => 'กาแฟ', 'type' => 'expense']);
    assertSame(201, $category['status']);
    $categoryId = (int)$client->json($category)['id'];
    $month      = date('Y-m');

    aiChatQueue([
        aiChatJson(['command' => 'add_finance', 'args' => [
            'type' => 'expense', 'amount' => 120.5, 'description' => 'กาแฟเย็น ' . TEST_RUN_ID,
            'date' => date('Y-m-d'), 'category' => 'กาแฟ',
        ]]),
        aiChatJson(['reply' => 'บันทึกแล้ว']),
    ]);
    assertSame(200, aiChatSend($client, 'จ่ายกาแฟ 120.50')['status']);

    aiChatQueue([
        aiChatJson(['command' => 'add_finance', 'args' => [
            'type' => 'expense', 'amount' => 40, 'description' => 'ก้าแฟ ' . TEST_RUN_ID,
            'date' => date('Y-m-d'), 'category' => 'ก้าแฟ',
        ]]),
        aiChatJson(['reply' => 'บันทึกแล้ว']),
    ]);
    assertSame(200, aiChatSend($client, 'จ่ายก้าแฟ 40')['status']);

    $list = $client->json($client->get('/api/finance?month=' . $month))['transactions'] ?? [];
    $exact = $tone = null;
    foreach ($list as $txn) {
        if (($txn['description'] ?? '') === 'กาแฟเย็น ' . TEST_RUN_ID) $exact = $txn;
        if (($txn['description'] ?? '') === 'ก้าแฟ ' . TEST_RUN_ID) $tone = $txn;
    }
    assertTrue($exact !== null && $tone !== null, 'both transactions should be stored');
    assertSame($categoryId, (int)($exact['category_id'] ?? 0), 'the exact name should match the category');
    assertSame(null, $tone['category_id'], 'a tone-mark variant must not match by collation');

    // list_finance: ผลที่ป้อนกลับให้ AI มีสรุปและรายการของเดือนนี้
    aiChatQueue([
        aiChatJson(['command' => 'list_finance', 'args' => ['month' => $month]]),
        aiChatJson(['reply' => 'สรุปให้แล้ว']),
    ]);
    aiChatSend($client, 'สรุปการเงินเดือนนี้');
    $result = aiChatResultOf(fakeLlmRequests()[1]);
    assertStringContains('รายจ่าย', $result);
    assertStringContains('120.50', $result);
    assertStringContains('กาแฟ', $result);
});

// --- การตรวจข้อมูลขาเข้า ---

test('the chat API rejects a missing key, unconfirmed commands and long messages', function (TestClient $_c): void {
    $client = aiChatClient('validation');

    $noKey = aiChatSend($client, 'สวัสดี', 'openai');
    assertSame(422, $noKey['status']);
    assertStringContains('ยังไม่ได้ตั้งค่า API key', (string)($client->json($noKey)['error'] ?? ''));

    $notDestructive = $client->post('/api/ai/chat/confirm', ['command' => 'add_task', 'args' => ['title' => 'x']]);
    assertSame(422, $notDestructive['status']);
    assertSame('AI ทำคำสั่งนี้ไม่ได้', $client->json($notDestructive)['error'] ?? null);

    aiChatSaveKey($client);
    $long = $client->post('/api/ai/chat', [
        'provider' => 'custom',
        'messages' => [['role' => 'user', 'content' => str_repeat('ก', 2001)]],
    ]);
    assertSame(422, $long['status']);
});

test('a rejected provider key becomes a 502 with a Thai explanation', function (TestClient $_c): void {
    $client = aiChatClient('badkey');
    aiChatSaveKey($client, 'bad-key');
    aiChatQueue([aiChatJson(['reply' => 'ไม่ควรถึงตรงนี้'])]);

    $response = aiChatSend($client, 'สวัสดี');
    assertSame(502, $response['status']);
    assertStringContains('ไม่ถูกต้อง', (string)($client->json($response)['error'] ?? ''));
});
