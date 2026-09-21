<?php
// =====================================================
// tests/integration/file_tools_test.php
//
// FileToolsController takes uploads from anyone signed in and hands back a
// converted image or an archive, which makes it the widest input surface in
// the app after the file manager. 459 lines, no tests until now.
// =====================================================

declare(strict_types=1);

function toolsClient(): TestClient
{
    static $client = null;
    if ($client !== null) return $client;

    $client = new TestClient(TEST_BASE_URL);
    $client->login('tools_' . TEST_RUN_ID, 'TestPass123!');
    return $client;
}

/** A real PNG, so the server's own image checks have something to read. */
function pngFixture(int $width = 40, int $height = 20): string
{
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 20, 120, 200));

    ob_start();
    imagepng($image);
    $bytes = (string)ob_get_clean();
    imagedestroy($image);

    return $bytes;
}

/** A real ZIP built in memory, entry name => contents. */
function zipFixture(array $entries): string
{
    $path = tempnam(sys_get_temp_dir(), 'fixture-zip-') . '.zip';

    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    foreach ($entries as $name => $contents) {
        $zip->addFromString($name, $contents);
    }
    $zip->close();

    $bytes = (string)file_get_contents($path);
    @unlink($path);
    return $bytes;
}

// --- Image tool ---

test('an image converts to another format', function (TestClient $_c): void {
    $response = toolsClient()->uploadMany('/api/file-tools/image', 'file',
        [['name' => 'square.png', 'contents' => pngFixture(), 'type' => 'image/png']],
        ['action' => 'convert', 'format' => 'jpeg']
    );

    assertSame(200, $response['status'], 'convert failed: ' . substr($response['body'], 0, 200));
    assertStringContains('image/jpeg', $response['headers']);
    // The returned bytes must really be a JPEG, not a renamed PNG.
    assertSame('image/jpeg', (new finfo(FILEINFO_MIME_TYPE))->buffer($response['body']));
});

test('an image resizes to the requested width', function (TestClient $_c): void {
    $response = toolsClient()->uploadMany('/api/file-tools/image', 'file',
        [['name' => 'wide.png', 'contents' => pngFixture(400, 200), 'type' => 'image/png']],
        ['action' => 'resize', 'width' => '100']
    );

    assertSame(200, $response['status'], 'resize failed: ' . substr($response['body'], 0, 200));

    $size = getimagesizefromstring($response['body']);
    assertTrue($size !== false, 'the response should be a readable image');
    assertSame(100, $size[0], 'the width should be what was asked for');
});

test('a compressed image is still a valid image', function (TestClient $_c): void {
    $response = toolsClient()->uploadMany('/api/file-tools/image', 'file',
        [['name' => 'photo.png', 'contents' => pngFixture(200, 200), 'type' => 'image/png']],
        ['action' => 'compress', 'quality' => '60']
    );

    assertSame(200, $response['status'], 'compress failed: ' . substr($response['body'], 0, 200));
    assertTrue(getimagesizefromstring($response['body']) !== false);
});

test('a file that is not an image is refused', function (TestClient $_c): void {
    // The check reads the bytes rather than trusting the name or the sent
    // content type, so a PHP script renamed to .png must still be caught.
    $response = toolsClient()->uploadMany('/api/file-tools/image', 'file',
        [['name' => 'evil.png', 'contents' => "<?php echo 'hello'; ?>", 'type' => 'image/png']],
        ['action' => 'convert', 'format' => 'png']
    );

    assertSame(422, $response['status']);
    assertStringContains('รูปภาพ', $response['body']);
});

test('an unknown image action is refused', function (TestClient $_c): void {
    $response = toolsClient()->uploadMany('/api/file-tools/image', 'file',
        [['name' => 'x.png', 'contents' => pngFixture(), 'type' => 'image/png']],
        ['action' => 'enhance-with-ai']
    );

    assertSame(422, $response['status']);
});

test('the image tool needs a file', function (TestClient $_c): void {
    assertSame(422, toolsClient()->post('/api/file-tools/image', ['action' => 'convert'])['status']);
});

// --- ZIP creation ---

test('several files are packed into one archive', function (TestClient $_c): void {
    $response = toolsClient()->uploadMany('/api/file-tools/zip/create', 'files', [
        ['name' => 'one.txt', 'contents' => 'first', 'type' => 'text/plain'],
        ['name' => 'two.txt', 'contents' => 'second', 'type' => 'text/plain'],
    ], ['name' => 'bundle']);

    assertSame(200, $response['status'], 'zip create failed: ' . substr($response['body'], 0, 200));
    assertStringContains('application/zip', $response['headers']);
    assertStringContains('bundle.zip', $response['headers']);
    assertSame('PK', substr($response['body'], 0, 2), 'the body should be a real archive');
});

test('a script file cannot be packed', function (TestClient $_c): void {
    // Not because the archive itself is dangerous, but because the tool is a
    // convenient way to smuggle one onto the server.
    $response = toolsClient()->uploadMany('/api/file-tools/zip/create', 'files', [
        ['name' => 'shell.php', 'contents' => '<?php ?>', 'type' => 'text/plain'],
    ], ['name' => 'bundle']);

    assertSame(422, $response['status']);
    assertStringContains('ไม่อนุญาต', $response['body']);
});

test('zip creation needs at least one file', function (TestClient $_c): void {
    assertSame(422, toolsClient()->post('/api/file-tools/zip/create', ['name' => 'empty'])['status']);
});

// --- ZIP inspection ---

test('an archive lists its entries with sizes', function (TestClient $_c): void {
    $zip = zipFixture(['readme.txt' => 'hello there', 'data/notes.txt' => 'more']);

    $data = toolsClient()->json(toolsClient()->uploadMany('/api/file-tools/zip/inspect', 'file',
        [['name' => 'bundle.zip', 'contents' => $zip, 'type' => 'application/zip']]
    ));

    assertSame(2, $data['total'] ?? 0);
    $names = array_column($data['entries'] ?? [], 'name');
    assertContains('readme.txt', $names);
    assertContains('data/notes.txt', $names, 'a nested path is listed as stored');
});

test('a file that is not an archive is refused', function (TestClient $_c): void {
    $response = toolsClient()->uploadMany('/api/file-tools/zip/inspect', 'file',
        [['name' => 'fake.zip', 'contents' => 'definitely not a zip', 'type' => 'application/zip']]
    );

    assertSame(422, $response['status']);
});

// --- ZIP extraction ---

test('a single entry is streamed back on its own', function (TestClient $_c): void {
    $zip = zipFixture(['only.txt' => 'the contents']);

    $response = toolsClient()->uploadMany('/api/file-tools/zip/extract', 'file',
        [['name' => 'bundle.zip', 'contents' => $zip, 'type' => 'application/zip']],
        ['indices' => '0']
    );

    assertSame(200, $response['status'], 'extract failed: ' . substr($response['body'], 0, 200));
    assertSame('the contents', $response['body']);
    assertStringContains('only.txt', $response['headers']);
});

test('extracting several entries returns a new archive', function (TestClient $_c): void {
    $zip = zipFixture(['a.txt' => 'aaa', 'b.txt' => 'bbb']);

    $response = toolsClient()->uploadMany('/api/file-tools/zip/extract', 'file',
        [['name' => 'bundle.zip', 'contents' => $zip, 'type' => 'application/zip']]
    );

    assertSame(200, $response['status']);
    assertSame('PK', substr($response['body'], 0, 2));
    assertStringContains('extracted.zip', $response['headers']);
});

test('an entry named to escape its folder is flattened', function (TestClient $_c): void {
    // Zip slip: an archive whose entry is ../../something. Nothing is written
    // to disk here, but the name also reaches a download header, so it must
    // come back as a plain filename.
    $zip = zipFixture(['../../escaped.txt' => 'payload']);

    $response = toolsClient()->uploadMany('/api/file-tools/zip/extract', 'file',
        [['name' => 'evil.zip', 'contents' => $zip, 'type' => 'application/zip']],
        ['indices' => '0']
    );

    assertSame(200, $response['status']);
    assertStringContains('escaped.txt', $response['headers']);
    assertTrue(!str_contains($response['headers'], '../'), 'the traversal must not survive into the header');
});

test('extraction needs a real archive', function (TestClient $_c): void {
    $response = toolsClient()->uploadMany('/api/file-tools/zip/extract', 'file',
        [['name' => 'fake.zip', 'contents' => 'not an archive at all', 'type' => 'application/zip']]
    );

    assertSame(422, $response['status']);
});

// --- Access ---

test('the file tools need a session', function (TestClient $_c): void {
    $anonymous = new TestClient(TEST_BASE_URL);

    foreach (['/api/file-tools/image', '/api/file-tools/zip/create',
              '/api/file-tools/zip/inspect', '/api/file-tools/zip/extract'] as $path) {
        assertContains($anonymous->post($path, [])['status'], [401, 302, 403], $path . ' must require a session');
    }
});
