<?php
// =====================================================
// core/WebPush.php — VAPID-signed Web Push
//
// Pushes carry no payload. A push service (Google, Mozilla, Apple) only ever
// sees "wake this browser up"; the service worker then fetches the actual
// notification from DayFlow over the user's own session. That keeps message
// content off third-party infrastructure entirely, and it means this file only
// needs VAPID's ES256 signature rather than the ECDH/HKDF payload encryption
// of RFC 8291.
//
// Keys are generated once with scripts/generate-vapid-keys.php and supplied
// through VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY.
// =====================================================

final class WebPush
{
    /** How long a signed VAPID token stays valid. Must be under 24 hours. */
    private const TOKEN_TTL = 12 * 3600;

    public static function isConfigured(): bool
    {
        return VAPID_PUBLIC_KEY !== '' && VAPID_PRIVATE_KEY !== '';
    }

    public static function publicKey(): string
    {
        return VAPID_PUBLIC_KEY;
    }

    /**
     * Sends one wake-up push.
     *
     * @return array{ok: bool, status: int, gone: bool} `gone` marks a
     *         subscription the push service has permanently rejected, which
     *         the caller should delete.
     */
    public static function send(array $subscription, int $ttlSeconds = 3600): array
    {
        if (!self::isConfigured()) {
            return ['ok' => false, 'status' => 0, 'gone' => false];
        }

        $endpoint = (string)($subscription['endpoint'] ?? '');
        if ($endpoint === '' || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return ['ok' => false, 'status' => 0, 'gone' => true];
        }

        $token = self::vapidToken($endpoint);
        if ($token === null) {
            return ['ok' => false, 'status' => 0, 'gone' => false];
        }

        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => '',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'TTL: ' . $ttlSeconds,
                'Content-Length: 0',
                'Urgency: normal',
                'Authorization: vapid t=' . $token . ', k=' . VAPID_PUBLIC_KEY,
            ],
        ]);

        curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error  = curl_error($ch);
        curl_close($ch);

        if ($error !== '') {
            error_log('Web push transport error: ' . $error);
        }

        return [
            'ok'     => $status >= 200 && $status < 300,
            'status' => $status,
            // 404/410 mean the browser unsubscribed or the endpoint expired.
            'gone'   => in_array($status, [404, 410], true),
        ];
    }

    /**
     * Builds the signed VAPID JWT for one push service origin.
     * Returns null when the configured key cannot be loaded.
     */
    private static function vapidToken(string $endpoint): ?string
    {
        $parts = parse_url($endpoint);
        if (!isset($parts['scheme'], $parts['host'])) return null;

        $audience = $parts['scheme'] . '://' . $parts['host'];

        $header = ['typ' => 'JWT', 'alg' => 'ES256'];
        $claims = [
            'aud' => $audience,
            'exp' => time() + self::TOKEN_TTL,
            'sub' => VAPID_SUBJECT !== '' ? VAPID_SUBJECT : 'mailto:admin@' . (parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost'),
        ];

        $signingInput = self::base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES) ?: '')
            . '.' . self::base64UrlEncode(json_encode($claims, JSON_UNESCAPED_SLASHES) ?: '');

        $signature = self::signEs256($signingInput);
        if ($signature === null) return null;

        return $signingInput . '.' . self::base64UrlEncode($signature);
    }

    /**
     * ES256 over the signing input, as the 64-byte R||S form JWT requires.
     * openssl_sign hands back ASN.1 DER, so it has to be unpacked.
     */
    private static function signEs256(string $input): ?string
    {
        $pem = self::privateKeyPem();
        if ($pem === null) return null;

        $key = openssl_pkey_get_private($pem);
        if ($key === false) {
            error_log('Web push: VAPID private key could not be parsed');
            return null;
        }

        $der = '';
        if (!openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            error_log('Web push: signing failed');
            return null;
        }

        return self::derToRawSignature($der);
    }

    /**
     * The configured private key as PEM.
     *
     * Accepts either a PEM block or the raw base64url 32-byte scalar that the
     * web-push tooling usually produces, in which case the PEM is assembled
     * around it.
     */
    private static function privateKeyPem(): ?string
    {
        $configured = trim(VAPID_PRIVATE_KEY);
        if ($configured === '') return null;

        if (str_contains($configured, 'BEGIN')) {
            return str_replace('\n', "\n", $configured);
        }

        $scalar = self::base64UrlDecode($configured);
        if (strlen($scalar) !== 32) {
            error_log('Web push: VAPID private key is not 32 bytes');
            return null;
        }

        $publicPoint = self::base64UrlDecode(VAPID_PUBLIC_KEY);
        if (strlen($publicPoint) !== 65 || $publicPoint[0] !== "\x04") {
            error_log('Web push: VAPID public key is not an uncompressed P-256 point');
            return null;
        }

        // SEC1 ECPrivateKey for prime256v1, wrapped as PEM. The fixed prefixes
        // are the DER for the version, the curve OID and the bit-string tag.
        $der = "\x30\x77\x02\x01\x01\x04\x20" . $scalar
             . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
             . "\xa1\x44\x03\x42\x00" . $publicPoint;

        return "-----BEGIN EC PRIVATE KEY-----\n"
             . chunk_split(base64_encode($der), 64, "\n")
             . "-----END EC PRIVATE KEY-----\n";
    }

    /** Converts an ASN.1 DER ECDSA signature into the fixed 64-byte form. */
    private static function derToRawSignature(string $der): ?string
    {
        $offset = 0;
        if (($der[$offset++] ?? '') !== "\x30") return null;

        $seqLength = ord($der[$offset++] ?? "\x00");
        if ($seqLength & 0x80) {
            // Long form: the low bits say how many length bytes follow.
            $offset += $seqLength & 0x7f;
        }

        $readInteger = static function (string $der, int &$offset): ?string {
            if (($der[$offset++] ?? '') !== "\x02") return null;
            $length = ord($der[$offset++] ?? "\x00");
            $value  = substr($der, $offset, $length);
            $offset += $length;

            // DER integers are signed, so a leading zero may pad a high bit.
            $value = ltrim($value, "\x00");
            if (strlen($value) > 32) return null;

            return str_pad($value, 32, "\x00", STR_PAD_LEFT);
        };

        $r = $readInteger($der, $offset);
        $s = $readInteger($der, $offset);

        return ($r === null || $s === null) ? null : $r . $s;
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $padded = strtr($value, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);
        return (string)base64_decode($padded, true);
    }
}
