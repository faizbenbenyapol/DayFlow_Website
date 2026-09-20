<?php
// =====================================================
// tests/integration/notes_test.php
// =====================================================

declare(strict_types=1);

test('creating an encrypted note does not return its password hash', function (TestClient $client): void {
    $data = $client->json($client->post('/api/notes', [
        'title' => 'โน้ตเข้ารหัส', 'is_encrypted' => true, 'password' => 'SecretPass123',
    ]));

    assertArrayHasKey('note', $data);
    // The bcrypt hash is crackable offline once it reaches a browser, and the
    // salt is what the note body is encrypted with.
    assertArrayNotHasKey('password_hash', $data['note']);
    assertArrayNotHasKey('encrypt_salt', $data['note']);
    assertArrayHasKey('id', $data['note'], 'the client still needs the id');
    assertArrayHasKey('title', $data['note']);
});

test('an encrypted note still unlocks with the right password', function (TestClient $client): void {
    $note = $client->json($client->post('/api/notes', [
        'title' => 'ตรวจรหัสผ่าน', 'is_encrypted' => true, 'password' => 'SecretPass123',
    ]))['note'];

    $wrong = $client->post('/api/notes/' . (int)$note['id'] . '/verify', ['password' => 'nope']);
    assertSame(401, $wrong['status'], 'a wrong password must be rejected');
    assertTrue(!str_contains($wrong['body'], 'password_hash'), 'the failure must not echo the hash');

    $right = $client->post('/api/notes/' . (int)$note['id'] . '/verify', ['password' => 'SecretPass123']);
    assertSame(200, $right['status'], 'the correct password must still unlock the note');
});

test('an encrypted block round-trips through storage as ciphertext', function (TestClient $client): void {
    $note = $client->json($client->post('/api/notes', [
        'title' => 'เนื้อหาเข้ารหัส', 'is_encrypted' => true, 'password' => 'SecretPass123',
    ]))['note'];

    $plain = 'ข้อความลับที่ต้องกลับมาเหมือนเดิม';
    $created = $client->post('/api/notes/' . (int)$note['id'] . '/blocks', [
        'type' => 'text', 'content' => $plain, 'password' => 'SecretPass123',
    ]);
    assertSame(201, $created['status'], 'block creation failed: ' . $created['body']);

    $unlocked = $client->json($client->post('/api/notes/' . (int)$note['id'] . '/verify', [
        'password' => 'SecretPass123',
    ]));

    $contents = array_column($unlocked['blocks'] ?? [], 'content');
    assertContains($plain, $contents, 'the decrypted block must match what was written');
});

test('a note belonging to someone else is not readable', function (TestClient $client): void {
    $note = $client->json($client->post('/api/notes', ['title' => 'ของเจ้าของเดิม', 'is_encrypted' => false]))['note'];

    $other = new TestClient(TEST_BASE_URL);
    $other->login('dayflow_test_other', 'TestPass123!');

    $response = $other->get('/api/notes/' . (int)$note['id'] . '/blocks');
    assertContains($response['status'], [403, 404], 'another account must not read the note');
});
