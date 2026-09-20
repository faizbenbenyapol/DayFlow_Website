<?php
// =====================================================
// tests/integration/two_factor_test.php
//
// Enrolment, the login challenge and recovery codes, driven over HTTP.
// Each case uses its own account so turning 2FA on does not affect the shared
// client the other suites rely on.
// =====================================================

declare(strict_types=1);

/**
 * Signs in a throwaway account and returns its client plus password.
 *
 * @return array{0: TestClient, 1: string, 2: string}
 */
function freshAccount(string $label): array
{
    $username = 'tfa_' . $label . '_' . TEST_RUN_ID;
    $password = 'TestPass123!';

    $client = new TestClient(TEST_BASE_URL);
    $client->login($username, $password);

    return [$client, $username, $password];
}

/** Completes enrolment and returns [secret, recovery codes]. */
function enrol(TestClient $client, string $password): array
{
    $begin = $client->json($client->post('/api/settings/two-factor/begin', ['password' => $password]));
    $secret = $begin['secret'] ?? '';
    if ($secret === '') throw new RuntimeException('enrolment did not return a secret: ' . json_encode($begin));

    $confirm = $client->json($client->post('/api/settings/two-factor/confirm', [
        'code' => Totp::codeAt($secret),
    ]));

    return [$secret, $confirm['recovery_codes'] ?? []];
}

test('two-factor is off for a new account', function (TestClient $_client): void {
    [$client] = freshAccount('off');

    $status = $client->json($client->get('/api/settings/two-factor'));
    assertFalse($status['enabled']);
    assertFalse($status['pending']);
});

test('enrolment needs the account password', function (TestClient $_client): void {
    [$client] = freshAccount('pw');

    $response = $client->post('/api/settings/two-factor/begin', ['password' => 'not-the-password']);
    assertSame(401, $response['status'], 'an open session alone must not be enough');
});

test('enrolment returns a usable secret and provisioning URI', function (TestClient $_client): void {
    [$client, , $password] = freshAccount('begin');

    $begin = $client->json($client->post('/api/settings/two-factor/begin', ['password' => $password]));

    assertTrue((bool)preg_match('/^[A-Z2-7]{32}$/', $begin['secret'] ?? ''), 'not a base32 secret');
    assertStringContains('otpauth://totp/', $begin['uri'] ?? '');
    assertStringContains('secret=' . $begin['secret'], $begin['uri']);

    // Begun but not confirmed: logging in must not be challenged yet.
    $status = $client->json($client->get('/api/settings/two-factor'));
    assertFalse($status['enabled']);
    assertTrue($status['pending']);
});

test('a wrong code does not confirm enrolment', function (TestClient $_client): void {
    [$client, , $password] = freshAccount('badcode');
    $client->post('/api/settings/two-factor/begin', ['password' => $password]);

    $response = $client->post('/api/settings/two-factor/confirm', ['code' => '000000']);
    assertSame(401, $response['status']);
    assertFalse($client->json($client->get('/api/settings/two-factor'))['enabled']);
});

test('confirming enrolment turns it on and hands over recovery codes', function (TestClient $_client): void {
    [$client, , $password] = freshAccount('confirm');
    [, $codes] = enrol($client, $password);

    assertSame(8, count($codes), 'a full set of recovery codes should be issued');

    $status = $client->json($client->get('/api/settings/two-factor'));
    assertTrue($status['enabled']);
    assertSame(8, $status['recovery_codes_left']);
});

test('the password alone no longer opens a session', function (TestClient $_client): void {
    [$client, $username, $password] = freshAccount('challenge');
    enrol($client, $password);

    $fresh = new TestClient(TEST_BASE_URL);
    $fresh->get('/login');
    $login = $fresh->json($fresh->post('/api/auth/login', ['identifier' => $username, 'password' => $password]));

    assertTrue($login['two_factor_required'] ?? false, 'login must ask for a second factor');
    assertArrayNotHasKey('redirect', $login, 'no session should be handed out yet');

    // And the half-finished login really has no access.
    assertContains($fresh->get('/api/dashboard/summary')['status'], [401, 302]);
});

test('a valid code completes the login', function (TestClient $_client): void {
    [$client, $username, $password] = freshAccount('complete');
    [$secret] = enrol($client, $password);

    $fresh = new TestClient(TEST_BASE_URL);
    $fresh->get('/login');
    $fresh->post('/api/auth/login', ['identifier' => $username, 'password' => $password]);

    $second = $fresh->json($fresh->post('/api/auth/two-factor', ['code' => Totp::codeAt($secret)]));
    assertArrayHasKey('redirect', $second, 'a correct code should finish the login');

    assertSame(200, $fresh->get('/api/dashboard/summary')['status']);
});

test('a wrong code does not complete the login', function (TestClient $_client): void {
    [$client, $username, $password] = freshAccount('wrongcode');
    enrol($client, $password);

    $fresh = new TestClient(TEST_BASE_URL);
    $fresh->get('/login');
    $fresh->post('/api/auth/login', ['identifier' => $username, 'password' => $password]);

    assertSame(401, $fresh->post('/api/auth/two-factor', ['code' => '000000'])['status']);
    assertContains($fresh->get('/api/dashboard/summary')['status'], [401, 302]);
});

test('the challenge endpoint refuses a session with nothing pending', function (TestClient $_client): void {
    $stranger = new TestClient(TEST_BASE_URL);
    $stranger->get('/login');

    assertSame(401, $stranger->post('/api/auth/two-factor', ['code' => '123456'])['status']);
});

test('a recovery code works once and then is spent', function (TestClient $_client): void {
    [$client, $username, $password] = freshAccount('recovery');
    [, $codes] = enrol($client, $password);
    $code = $codes[0];

    $first = new TestClient(TEST_BASE_URL);
    $first->get('/login');
    $first->post('/api/auth/login', ['identifier' => $username, 'password' => $password]);
    $used = $first->json($first->post('/api/auth/two-factor', ['code' => $code]));
    assertArrayHasKey('redirect', $used, 'a recovery code should complete the login');

    // The same code a second time must be refused.
    $second = new TestClient(TEST_BASE_URL);
    $second->get('/login');
    $second->post('/api/auth/login', ['identifier' => $username, 'password' => $password]);
    assertSame(401, $second->post('/api/auth/two-factor', ['code' => $code])['status']);

    assertSame(7, $client->json($client->get('/api/settings/two-factor'))['recovery_codes_left']);
});

test('recovery codes can be regenerated, retiring the old set', function (TestClient $_client): void {
    [$client, , $password] = freshAccount('regen');
    [, $original] = enrol($client, $password);

    $fresh = $client->json($client->post('/api/settings/two-factor/recovery', ['password' => $password]));
    $replacement = $fresh['recovery_codes'] ?? [];

    assertSame(8, count($replacement));
    assertSame([], array_intersect($original, $replacement), 'the new set must not reuse old codes');
});

test('disabling needs the password and restores single-step login', function (TestClient $_client): void {
    [$client, $username, $password] = freshAccount('disable');
    enrol($client, $password);

    assertSame(401, $client->request('DELETE', '/api/settings/two-factor', ['password' => 'wrong'])['status']);

    $client->request('DELETE', '/api/settings/two-factor', ['password' => $password]);
    assertFalse($client->json($client->get('/api/settings/two-factor'))['enabled']);

    $fresh = new TestClient(TEST_BASE_URL);
    $fresh->get('/login');
    $login = $fresh->json($fresh->post('/api/auth/login', ['identifier' => $username, 'password' => $password]));
    assertArrayHasKey('redirect', $login, 'with 2FA off the password should be enough again');
});
