<?php
// =====================================================
// tests/integration/transfer_test.php
//
// ส่งไฟล์แบบแบ่งชิ้น (เฟส 3 REQ-16–19, EDGE-6–10): ไฟล์ทุกประเภทกลับมาตรงทุกไบต์
// ส่งแค่ข้อความได้ เวลาหมดอายุเริ่มนับเมื่ออัปโหลดเสร็จ และของที่ค้างหรือหมดอายุถูกลบ
// =====================================================

declare(strict_types=1);

require_once ROOT . '/models/FileTransfer.php';

/** บัญชีผู้ส่งต่อ label */
function transferClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];
    $client = new TestClient(TEST_BASE_URL);
    $client->login('tf_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    return $clients[$label] = $client;
}

/** ผู้รับที่ไม่มีบัญชี */
function stranger(): TestClient
{
    $client = new TestClient(TEST_BASE_URL);
    $client->get('/login');
    return $client;
}

test('files of any type, sent in chunks, come back byte for byte and always as a download', function (TestClient $_c): void {
    $files = [
        'setup.exe'   => random_bytes(300),
        'shell.php'   => '<?php echo "never run"; ?>',
        'page.html'   => '<script>alert(1)</script>',
        'archive.zip' => random_bytes(20000),
        'big.bin'     => random_bytes(8 * 1024 * 1024 + 5),   // เกินหนึ่งชิ้น
    ];
    $sent = transferClient('types')->transfer($files);
    assertSame(200, $sent['status'], json_encode($sent));
    assertTrue((bool)preg_match('/^\d{6}$/', (string)$sent['code']), 'a six-digit code');
    assertSame(5, count($sent['files'] ?? []));

    $receiver = stranger();
    foreach (array_values($files) as $index => $bytes) {
        $download = $receiver->get('/transfer/download/' . $sent['token'] . '/' . $index);
        assertSame(200, $download['status'], "file {$index}");
        assertSame(md5($bytes), md5($download['body']), "file {$index} arrives byte for byte");
        assertStringContains('Content-Disposition: attachment', $download['headers'], "file {$index} is a download");
        assertStringContains('Content-Type: application/octet-stream', $download['headers'], "file {$index} is never rendered as a page");
    }
});

test('a message alone can be sent, and the receiver sees it with its link', function (TestClient $_c): void {
    $sent = transferClient('message')->transfer([], "ดูนี่ครับ https://example.com/a?b=1&c=2\nบรรทัดสอง");
    assertSame(201, $sent['status'], json_encode($sent));

    $received = stranger()->post('/api/transfer/receive', ['code' => $sent['code']]);
    $data = json_decode($received['body'], true);
    assertSame("ดูนี่ครับ https://example.com/a?b=1&c=2\nบรรทัดสอง", $data['message'] ?? null);
    assertSame(0, $data['files_count'] ?? null);

    $page = stranger()->get('/transfer/download/' . $sent['token'])['body'];
    assertStringContains('<a href="https://example.com/a?b=1&amp;c=2"', $page, 'the link can be clicked');
    assertStringContains('rel="noopener noreferrer nofollow"', $page);
});

test('nothing to send, a long message, a bad expiry or more than 10 GB is refused before any upload', function (TestClient $_c): void {
    $client = transferClient('refuse');
    $start = fn(array $body): int => $client->post('/api/transfer/start', $body + ['files' => [], 'message' => '', 'expiry_minutes' => 5])['status'];

    assertSame(422, $start(['message' => "  \n "]), 'no file and a blank message');
    assertSame(422, $start(['message' => str_repeat('ก', 10001)]), 'a message over 10,000 characters');
    assertSame(422, $start(['message' => 'x', 'expiry_minutes' => 7]), 'only 5, 15, 30 or 60 minutes');
    $tooBig = $client->post('/api/transfer/start', ['files' => [['name' => 'a', 'size' => 6 * 1024 ** 3], ['name' => 'b', 'size' => 4 * 1024 ** 3 + 1]], 'message' => '', 'expiry_minutes' => 5]);
    assertSame(422, $tooBig['status'], 'over 10 GB in total');
    assertStringContains('10 GB', $tooBig['body']);
});

test('the clock starts when the upload finishes, at the chosen length', function (TestClient $_c): void {
    $client = transferClient('clock');
    $start = $client->json($client->post('/api/transfer/start', ['files' => [['name' => 'a.txt', 'size' => 3]], 'message' => '', 'expiry_minutes' => 15]));
    assertFalse(isset($start['code']), 'no code until the files are all there');
    assertSame(422, $client->post("/api/transfer/{$start['id']}/finish", [])['status'], 'finishing with bytes missing is refused');

    $client->postRaw("/api/transfer/{$start['id']}/chunk?file=0&offset=0", 'abc');
    $done = $client->json($client->post("/api/transfer/{$start['id']}/finish", []));
    assertTrue($done['expires_in'] > 14 * 60 && $done['expires_in'] <= 15 * 60, 'fifteen minutes from now: ' . $done['expires_in']);

    $default = $client->transfer(['b.txt' => 'b'], '', 5);
    assertTrue($default['expires_in'] > 4 * 60 && $default['expires_in'] <= 5 * 60, 'five minutes: ' . $default['expires_in']);
});

test('a chunk must land inside its file, in order, and in your own transfer', function (TestClient $_c): void {
    $client = transferClient('chunks');
    $start = $client->json($client->post('/api/transfer/start', ['files' => [['name' => 'a.bin', 'size' => 10]], 'message' => '', 'expiry_minutes' => 5]));
    $chunk = fn(string $query, string $bytes, ?TestClient $as = null): int => ($as ?? $client)->postRaw("/api/transfer/{$start['id']}/chunk?{$query}", $bytes)['status'];

    assertSame(422, $chunk('file=0&offset=0', str_repeat('x', 11)), 'past the declared size');
    assertSame(422, $chunk('file=0&offset=5', 'xxxxx'), 'a gap before it');
    assertSame(422, $chunk('file=1&offset=0', 'x'), 'a file that was not declared');
    assertSame(404, $chunk('file=0&offset=0', 'x', transferClient('intruder')), 'someone else\'s transfer looks like none');

    // ส่งชิ้นเดิมซ้ำ (เช่นตอนลองใหม่หลังเน็ตหลุด) ได้ผลเหมือนส่งครั้งเดียว
    assertSame(200, $chunk('file=0&offset=0', 'hello'));
    assertSame(200, $chunk('file=0&offset=0', 'hello'));
    assertSame(200, $chunk('file=0&offset=5', 'world'));
    $done = $client->json($client->post("/api/transfer/{$start['id']}/finish", []));
    assertSame('helloworld', stranger()->get('/transfer/download/' . $done['token'] . '/0')['body']);
});

test('several files come as one ZIP too, when they are small enough', function (TestClient $_c): void {
    $sent = transferClient('zip')->transfer(['one.txt' => 'first', 'สอง.txt' => 'second']);
    $zip = stranger()->get('/transfer/download/' . $sent['token'] . '/all');
    assertSame(200, $zip['status']);
    $path = tempnam(sys_get_temp_dir(), 'tfz');
    file_put_contents($path, $zip['body']);
    $archive = new ZipArchive();
    assertTrue($archive->open($path) === true, 'a readable ZIP');
    assertSame('second', $archive->getFromName('สอง.txt'));
    $archive->close();
    unlink($path);

    assertTrue(FileTransfer::canZip([['size' => 1024 ** 3], ['size' => 1024 ** 3]]), 'two files of 1 GB: 2 GB is fine');
    assertFalse(FileTransfer::canZip([['size' => 1024 ** 3], ['size' => 1024 ** 3 + 1]]), 'over 2 GB: one file at a time');
    assertFalse(FileTransfer::canZip([['size' => 5]]), 'one file needs no ZIP');
});

test('an upload left unfinished and a transfer past its time are both removed with their files', function (TestClient $_c): void {
    $client = transferClient('cleanup');
    $stale = $client->json($client->post('/api/transfer/start', ['files' => [['name' => 'a.bin', 'size' => 4]], 'message' => '', 'expiry_minutes' => 5]));
    $client->postRaw("/api/transfer/{$stale['id']}/chunk?file=0&offset=0", 'ab');
    $done = $client->transfer(['b.txt' => 'bbb']);

    $paths = [];
    foreach ([(int)$stale['id'], (int)$done['id']] as $id) {
        $row = DB::run('SELECT files_json FROM file_transfers WHERE id = ?', [$id])->fetch();
        foreach (json_decode($row['files_json'], true) as $file) $paths[] = UPLOAD_DIR . $file['path'];
        DB::run('UPDATE file_transfers SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$id]);
    }
    foreach ($paths as $path) assertTrue(is_file($path), "{$path} is there before");

    FileTransfer::cleanup();

    foreach ($paths as $path) assertFalse(is_file($path), "{$path} is gone after");
    assertSame(404, stranger()->post('/api/transfer/receive', ['code' => $done['code']])['status'], 'the code no longer works');
});
