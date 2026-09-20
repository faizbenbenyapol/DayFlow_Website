<?php
// =====================================================
// core/Totp.php — time-based one-time passwords (RFC 6238)
//
// Written against the spec rather than pulled in as a dependency: the project
// has no Composer setup, and the algorithm is a HMAC plus some bit shifting.
// Compatible with Google Authenticator, Authy, 1Password and the rest, which
// all use SHA-1, 6 digits and a 30-second step.
// =====================================================

final class Totp
{
    public const DIGITS = 6;
    public const PERIOD = 30;

    /** How many steps either side of now are accepted, for clock drift. */
    private const WINDOW = 1;

    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * A fresh shared secret, base32-encoded as authenticator apps expect.
     * 20 bytes is the length RFC 4226 recommends for SHA-1.
     */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    /** The code for a given moment (defaults to now). */
    public static function codeAt(string $secret, ?int $timestamp = null): string
    {
        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        return self::hotp($secret, $counter);
    }

    /**
     * Checks a user-supplied code, allowing one step of drift either way.
     *
     * Comparison is constant-time, and every candidate is evaluated so a
     * failure does not take a different amount of time depending on how close
     * the guess was.
     */
    public static function verify(string $secret, string $code, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($code) !== self::DIGITS) return false;

        $counter = intdiv($timestamp ?? time(), self::PERIOD);
        $valid   = false;

        for ($offset = -self::WINDOW; $offset <= self::WINDOW; $offset++) {
            if (hash_equals(self::hotp($secret, $counter + $offset), $code)) {
                $valid = true;
            }
        }

        return $valid;
    }

    /**
     * The otpauth:// URI an authenticator app reads from a QR code.
     */
    public static function provisioningUri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/'
            . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?' . http_build_query([
                'secret' => $secret,
                'issuer' => $issuer,
                'algorithm' => 'SHA1',
                'digits' => self::DIGITS,
                'period' => self::PERIOD,
            ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Single-use recovery codes, for when the authenticator device is gone.
     * Returned in plain text once; only their hashes are ever stored.
     *
     * @return string[]
     */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            // Grouped for legibility when written down.
            $raw = strtoupper(bin2hex(random_bytes(5)));
            $codes[] = substr($raw, 0, 5) . '-' . substr($raw, 5, 5);
        }
        return $codes;
    }

    public static function normaliseRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code) ?? '');
    }

    // --- Internals ---

    private static function hotp(string $secret, int $counter): string
    {
        $key = self::base32Decode($secret);
        if ($key === '') return str_repeat('-', self::DIGITS); // never matches

        // The counter goes in as a 64-bit big-endian integer.
        $binary = pack('N*', 0, $counter);
        $hash   = hash_hmac('sha1', $binary, $key, true);

        // Dynamic truncation: the low nibble of the last byte picks the offset.
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $value  = ((ord($hash[$offset]) & 0x7f) << 24)
                | ((ord($hash[$offset + 1]) & 0xff) << 16)
                | ((ord($hash[$offset + 2]) & 0xff) << 8)
                |  (ord($hash[$offset + 3]) & 0xff);

        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private static function base32Encode(string $bytes): string
    {
        if ($bytes === '') return '';

        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0', STR_PAD_RIGHT))];
        }

        // No '=' padding: authenticator apps accept the unpadded form and it
        // is easier to type by hand.
        return $out;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $secret) ?? '');
        if ($secret === '') return '';

        $bits = '';
        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32, $char);
            if ($index === false) return '';
            $bits .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $out = '';
        foreach (str_split($bits, 8) as $chunk) {
            // A trailing partial group is padding, not data.
            if (strlen($chunk) === 8) $out .= chr(bindec($chunk));
        }

        return $out;
    }
}
