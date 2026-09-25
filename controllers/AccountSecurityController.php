<?php
// =====================================================
// controllers/AccountSecurityController.php — remembered devices and two-factor sign-in
// =====================================================

class AccountSecurityController
{
    public function apiDevices(): void
    {
        Response::json(['devices' => RememberToken::listForUser(Auth::userId())]);
    }

    public function apiDeviceRevoke(string $id): void
    {
        $tokenId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$tokenId) Response::json(['error' => 'อุปกรณ์ไม่ถูกต้อง'], 422);

        $revoked = RememberToken::revokeForUser((int)$tokenId, Auth::userId());
        if (!$revoked) Response::json(['error' => 'ไม่พบอุปกรณ์นี้'], 404);
        Response::json(['ok' => true]);
    }

    public function apiDevicesRevokeOthers(): void
    {
        $count = RememberToken::revokeOthers(Auth::userId());
        Response::json(['ok' => true, 'revoked' => $count]);
    }

    public function apiTwoFactorStatus(): void
    {
        $userId = Auth::userId();
        $row    = TwoFactor::forUser($userId);

        Response::json([
            'enabled'         => (bool)($row['is_enabled'] ?? false),
            'pending'         => $row !== null && empty($row['is_enabled']),
            'confirmed_at'    => $row['confirmed_at'] ?? null,
            'recovery_codes_left' => $row && !empty($row['is_enabled'])
                ? TwoFactor::countUnusedRecoveryCodes($userId)
                : 0,
        ]);
    }

    /**
     * Starts enrolment. The secret is returned once, for the QR code and for
     * anyone typing it in by hand; it is stored encrypted and never sent again.
     */
    public function apiTwoFactorBegin(): void
    {
        $userId = Auth::userId();

        // Turning on a second factor is a security change, so the current
        // password has to be re-entered even though the session is open.
        $hash = User::passwordHash($userId);
        if ($hash === null || !User::verifyPassword((string)Request::rawInput('password', ''), $hash)) {
            Response::json(['error' => 'รหัสผ่านไม่ถูกต้อง'], 401);
        }

        if (TwoFactor::isEnabled($userId)) {
            Response::json(['error' => 'เปิดใช้งานการยืนยันสองชั้นอยู่แล้ว'], 409);
        }

        $secret = TwoFactor::beginEnrolment($userId);
        $user   = User::findById($userId);

        Response::json([
            'ok'     => true,
            'secret' => $secret,
            'uri'    => Totp::provisioningUri(
                $secret,
                (string)($user['email'] ?? $user['username'] ?? 'user'),
                APP_NAME
            ),
        ]);
    }

    public function apiTwoFactorConfirm(): void
    {
        $userId = Auth::userId();
        $code   = (string)Request::rawInput('code', '');

        if ($code === '') {
            Response::json(['error' => 'กรุณากรอกรหัสจากแอป'], 422);
        }

        $codes = TwoFactor::confirmEnrolment($userId, $code);
        if ($codes === null) {
            Response::json(['error' => 'รหัสไม่ถูกต้อง กรุณาลองใหม่'], 401);
        }

        // Shown once: from here on only their hashes exist.
        Response::json(['ok' => true, 'recovery_codes' => $codes]);
    }

    public function apiTwoFactorRecovery(): void
    {
        $userId = Auth::userId();

        $hash = User::passwordHash($userId);
        if ($hash === null || !User::verifyPassword((string)Request::rawInput('password', ''), $hash)) {
            Response::json(['error' => 'รหัสผ่านไม่ถูกต้อง'], 401);
        }
        if (!TwoFactor::isEnabled($userId)) {
            Response::json(['error' => 'ยังไม่ได้เปิดใช้งานการยืนยันสองชั้น'], 409);
        }

        Response::json(['ok' => true, 'recovery_codes' => TwoFactor::issueRecoveryCodes($userId)]);
    }

    public function apiTwoFactorDisable(): void
    {
        $userId = Auth::userId();

        $hash = User::passwordHash($userId);
        if ($hash === null || !User::verifyPassword((string)Request::rawInput('password', ''), $hash)) {
            Response::json(['error' => 'รหัสผ่านไม่ถูกต้อง'], 401);
        }

        TwoFactor::disable($userId);
        Response::json(['ok' => true]);
    }
}
