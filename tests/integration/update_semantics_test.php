<?php
// =====================================================
// tests/integration/update_semantics_test.php
//
// MySQL's rowCount() after an UPDATE used to count only rows whose values
// changed. Saving a record unchanged therefore looked like "not found", and
// the workaround (rowCount() >= 0) reported success for rows that did not
// exist or belonged to someone else. The connection now counts matched rows.
// =====================================================

declare(strict_types=1);

function semanticsClient(string $label): TestClient
{
    static $clients = [];
    if (isset($clients[$label])) return $clients[$label];
    $client = new TestClient(TEST_BASE_URL);
    $client->login('sem_' . $label . '_' . TEST_RUN_ID, 'TestPass123!');
    $client->get('/habits');
    return $clients[$label] = $client;
}

test('saving a record without changing it succeeds', function (TestClient $_c): void {
    $client = semanticsClient('owner');

    $created = $client->json($client->post('/api/habits', ['name' => 'ดื่มน้ำ', 'color' => '#10b981', 'target_days' => 7]));
    $id = (int)$created['id'];

    $response = $client->request('PUT', "/api/habits/{$id}", ['name' => 'ดื่มน้ำ', 'color' => '#10b981', 'target_days' => 7]);
    assertSame(200, $response['status'], 'an unchanged save is still a save: ' . $response['body']);
});

test('renaming another account\'s exercise category is refused', function (TestClient $_c): void {
    $victim = semanticsClient('victim');
    $victim->get('/exercise');
    $category = $victim->json($victim->get('/api/exercise/categories'))['categories'][0];

    $response = semanticsClient('owner')->request('PUT', '/api/exercise/categories/' . (int)$category['id'], ['name' => 'ของคนอื่น']);
    assertSame(404, $response['status'], 'it used to answer {"ok":true}');

    $names = array_column($victim->json($victim->get('/api/exercise/categories'))['categories'], 'name');
    assertContains($category['name'], $names, 'and the category is untouched');
});

test('renaming a note tag tells "not yours" apart from "name taken"', function (TestClient $_c): void {
    $owner = semanticsClient('owner');
    $owner->get('/notes');
    $a = $owner->json($owner->post('/api/notes/tags', ['name' => 'งาน ' . TEST_RUN_ID]))['id'];
    $owner->post('/api/notes/tags', ['name' => 'บ้าน ' . TEST_RUN_ID]);

    assertSame(422, $owner->request('PUT', "/api/notes/tags/{$a}", ['name' => 'บ้าน ' . TEST_RUN_ID])['status'], 'name taken');
    assertSame(200, $owner->request('PUT', "/api/notes/tags/{$a}", ['name' => 'งาน ' . TEST_RUN_ID])['status'], 'unchanged name');

    $stranger = semanticsClient('victim');
    $stranger->get('/notes');
    assertSame(404, $stranger->request('PUT', "/api/notes/tags/{$a}", ['name' => 'ยึด'])['status'], 'not yours');
});

test('equal counts in the exercise breakdown come back in a stable order', function (TestClient $_c): void {
    $client = semanticsClient('stats');
    $client->get('/exercise');
    // ASCII names: MySQL's collation and PHP's sort() agree on those.
    foreach (['Yoga', 'Run', 'Swim'] as $type) {
        $client->post('/api/exercise', ['type' => $type, 'workout_date' => '2026-09-20', 'duration_min' => 30]);
    }

    $first  = array_column($client->json($client->get('/api/exercise/stats'))['by_type'] ?? [], 'type');
    $second = array_column($client->json($client->get('/api/exercise/stats'))['by_type'] ?? [], 'type');
    assertSame(3, count($first), 'three types logged');
    assertSame($first, $second);
    $sorted = $first;
    sort($sorted, SORT_STRING);
    assertSame($sorted, $first, 'ties are broken by name');
});
