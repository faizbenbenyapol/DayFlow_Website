<?php
// =====================================================
// tests/unit/totp_test.php — core/Totp.php
//
// The RFC 6238 test vectors are the real check here: if these pass, any
// authenticator app will agree with us.
// =====================================================

declare(strict_types=1);

require_once ROOT . '/core/Totp.php';

/** The RFC's SHA-1 seed "12345678901234567890", base32-encoded. */
const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

test('matches the RFC 6238 SHA-1 test vectors', function (): void {
    // Published vectors are 8 digits; DayFlow uses the standard 6, so each
    // expectation is the last six of the RFC value.
    $vectors = [
        59          => '287082', // 1970-01-01 00:00:59
        1111111109  => '081804',
        1111111111  => '050471',
        1234567890  => '005924',
        2000000000  => '279037',
    ];

    foreach ($vectors as $timestamp => $expected) {
        assertSame($expected, Totp::codeAt(RFC_SECRET, $timestamp), 'vector at t=' . $timestamp);
    }
});

test('a code is stable for the whole 30-second step', function (): void {
    $start = 1700000000 - (1700000000 % 30);
    assertSame(Totp::codeAt(RFC_SECRET, $start), Totp::codeAt(RFC_SECRET, $start + 29));
    assertTrue(
        Totp::codeAt(RFC_SECRET, $start) !== Totp::codeAt(RFC_SECRET, $start + 30),
        'the next step must produce a different code'
    );
});

test('verify accepts the current code', function (): void {
    $now = 1700000000;
    assertTrue(Totp::verify(RFC_SECRET, Totp::codeAt(RFC_SECRET, $now), $now));
});

test('verify tolerates one step of clock drift either way', function (): void {
    $now = 1700000000;
    assertTrue(Totp::verify(RFC_SECRET, Totp::codeAt(RFC_SECRET, $now - 30), $now), 'a slightly slow clock');
    assertTrue(Totp::verify(RFC_SECRET, Totp::codeAt(RFC_SECRET, $now + 30), $now), 'a slightly fast clock');
});

test('verify rejects a code from further away', function (): void {
    $now = 1700000000;
    assertFalse(Totp::verify(RFC_SECRET, Totp::codeAt(RFC_SECRET, $now - 120), $now));
    assertFalse(Totp::verify(RFC_SECRET, Totp::codeAt(RFC_SECRET, $now + 120), $now));
});

test('verify rejects malformed input', function (): void {
    $now = 1700000000;
    assertFalse(Totp::verify(RFC_SECRET, '', $now));
    assertFalse(Totp::verify(RFC_SECRET, '12345', $now), 'too short');
    assertFalse(Totp::verify(RFC_SECRET, '1234567', $now), 'too long');
    assertFalse(Totp::verify(RFC_SECRET, 'abcdef', $now), 'letters are not a code');
    assertFalse(Totp::verify('', '123456', $now), 'no secret means no match');
});

test('verify ignores spaces and dashes a user might type', function (): void {
    $now  = 1700000000;
    $code = Totp::codeAt(RFC_SECRET, $now);
    $spaced = substr($code, 0, 3) . ' ' . substr($code, 3);

    assertTrue(Totp::verify(RFC_SECRET, $spaced, $now));
});

test('a code from one secret does not verify against another', function (): void {
    $now   = 1700000000;
    $other = Totp::generateSecret();

    assertFalse(Totp::verify($other, Totp::codeAt(RFC_SECRET, $now), $now));
});

test('generated secrets are valid base32 and distinct', function (): void {
    $a = Totp::generateSecret();
    $b = Totp::generateSecret();

    assertTrue($a !== $b, 'two secrets must not collide');
    assertSame(32, strlen($a), '20 bytes encodes to 32 base32 characters');
    assertTrue((bool)preg_match('/^[A-Z2-7]+$/', $a), 'not base32: ' . $a);

    // A generated secret must actually work end to end.
    $now = 1700000000;
    assertTrue(Totp::verify($a, Totp::codeAt($a, $now), $now));
});

test('the provisioning URI carries what an authenticator app needs', function (): void {
    $uri = Totp::provisioningUri(RFC_SECRET, 'user@example.com', 'DayFlow');

    assertStringContains('otpauth://totp/DayFlow:user%40example.com', $uri);
    assertStringContains('secret=' . RFC_SECRET, $uri);
    assertStringContains('issuer=DayFlow', $uri);
    assertStringContains('digits=6', $uri);
    assertStringContains('period=30', $uri);
});

test('recovery codes are unique, formatted and normalisable', function (): void {
    $codes = Totp::generateRecoveryCodes(8);

    assertSame(8, count($codes));
    assertSame(8, count(array_unique($codes)), 'recovery codes must not repeat');

    foreach ($codes as $code) {
        assertTrue((bool)preg_match('/^[0-9A-F]{5}-[0-9A-F]{5}$/', $code), 'unexpected format: ' . $code);
    }

    // However the user types it back, it normalises to the same string.
    assertSame('ABCDE12345', Totp::normaliseRecoveryCode('abcde-12345'));
    assertSame('ABCDE12345', Totp::normaliseRecoveryCode('ABCDE 12345'));
    assertSame('ABCDE12345', Totp::normaliseRecoveryCode('ABCDE12345'));
});
