<?php
// =====================================================
// models/TwoFactor.php — TOTP enrolment and verification state
// =====================================================

class TwoFactor
{
    /** Number of recovery codes handed out when 2FA is confirmed. */
    private const RECOVERY_CODE_COUNT = 8;

    public static function forUser(int $userId): ?array
    {
        return DB::run(
            'SELECT user_id, secret, is_enabled, confirmed_at FROM user_two_factor WHERE user_id = ?',
            [$userId]
        )->fetch() ?: null;
    }

    public static function isEnabled(int $userId): bool
    {
        return (bool)DB::run(
            'SELECT 1 FROM user_two_factor WHERE user_id = ? AND is_enabled = 1',
            [$userId]
        )->fetchColumn();
    }

    /**
     * Starts (or restarts) enrolment: a fresh secret, not yet active.
     * Returns the plain secret so the QR code can be rendered once.
     */
    public static function beginEnrolment(int $userId): string
    {
        $secret = Totp::generateSecret();

        DB::run(
            'INSERT INTO user_two_factor (user_id, secret, is_enabled, confirmed_at)
             VALUES (?, ?, 0, NULL)
             ON DUPLICATE KEY UPDATE secret = VALUES(secret), is_enabled = 0, confirmed_at = NULL',
            [$userId, appEncrypt($secret)]
        );

        // Any codes from a previous set belong to the old secret.
        DB::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);

        return $secret;
    }

    /**
     * Confirms enrolment with a code from the user's authenticator app.
     * Returns the recovery codes in plain text — the only time they are
     * readable — or null when the code did not match.
     *
     * @return string[]|null
     */
    public static function confirmEnrolment(int $userId, string $code): ?array
    {
        $row = self::forUser($userId);
        if (!$row) return null;

        $secret = appDecrypt((string)$row['secret']);
        if ($secret === '' || !Totp::verify($secret, $code)) return null;

        DB::run(
            'UPDATE user_two_factor SET is_enabled = 1, confirmed_at = NOW() WHERE user_id = ?',
            [$userId]
        );

        return self::issueRecoveryCodes($userId);
    }

    /**
     * Checks a login challenge. Accepts either a TOTP code or one unused
     * recovery code, which is consumed on use.
     */
    public static function verifyChallenge(int $userId, string $code): bool
    {
        $row = self::forUser($userId);
        if (!$row || empty($row['is_enabled'])) return false;

        $secret = appDecrypt((string)$row['secret']);
        if ($secret !== '' && Totp::verify($secret, $code)) return true;

        return self::consumeRecoveryCode($userId, $code);
    }

    /** @return string[] */
    public static function issueRecoveryCodes(int $userId): array
    {
        DB::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);

        $codes = Totp::generateRecoveryCodes(self::RECOVERY_CODE_COUNT);
        $stmt  = DB::conn()->prepare(
            'INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (?, ?)'
        );

        foreach ($codes as $code) {
            $stmt->execute([$userId, self::hashRecoveryCode($code)]);
        }

        return $codes;
    }

    public static function countUnusedRecoveryCodes(int $userId): int
    {
        return (int)DB::run(
            'SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL',
            [$userId]
        )->fetchColumn();
    }

    public static function disable(int $userId): void
    {
        DB::run('DELETE FROM user_two_factor WHERE user_id = ?', [$userId]);
        DB::run('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
    }

    private static function consumeRecoveryCode(int $userId, string $code): bool
    {
        $normalised = Totp::normaliseRecoveryCode($code);
        if ($normalised === '') return false;

        // Marking it used in the same statement that matches it means a code
        // cannot be spent twice by two requests arriving together.
        return DB::run(
            'UPDATE user_recovery_codes SET used_at = NOW()
             WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$userId, self::hashRecoveryCode($normalised)]
        )->rowCount() > 0;
    }

    private static function hashRecoveryCode(string $code): string
    {
        // A recovery code is 40 bits of randomness, so a fast hash is fine:
        // there is nothing to guess the way there is with a chosen password.
        return hash('sha256', Totp::normaliseRecoveryCode($code));
    }
}
