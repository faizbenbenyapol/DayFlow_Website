<?php
// =====================================================
// controllers/AuthController.php
// =====================================================

require_once ROOT . '/models/User.php';

class AuthController
{
    public function showLogin(): void
    {
        if (!empty($_SESSION['user_id'])) {
            Response::redirect('/');
        }
        $pageTitle = 'เข้าสู่ระบบ';
        require ROOT . '/views/auth/login.php';
    }

    public function logout(): void
    {
        Auth::logout();
        Response::redirect('/login');
    }

    // Public "preview" entry point — logs visitors straight into the demo
    // account as a normal session (no registration, no read-only share mode).
    public function demo(): void
    {
        if (!empty($_SESSION['user_id'])) {
            Response::redirect('/');
            return;
        }

        $demoUser = User::findDemo();
        $user = $demoUser ? User::findById($demoUser['id']) : null;

        if (!$user) {
            Response::redirect('/login');
            return;
        }

        Auth::login($user);
        Response::redirect('/');
    }

    public function apiLogin(): void
    {
        $rateKey = 'login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!RateLimiter::hit($rateKey, 10, 900)) {
            Response::json(['error' => 'มีการลองเข้าสู่ระบบมากเกินไป กรุณารอประมาณ 15 นาที'], 429);
        }

        $identifier = Request::rawInput('identifier', ''); // username or email
        $password   = Request::rawInput('password',   '');
        $remember   = filter_var(Request::rawInput('remember_device', false), FILTER_VALIDATE_BOOLEAN);

        if (!$identifier || !$password) {
            Response::json(['error' => 'กรุณากรอกข้อมูลให้ครบ'], 422);
        }

        // Try email first, then username
        $user = User::findByEmail($identifier) ?? User::findByUsername($identifier);

        // Demo account is public read-only via /demo — never a normal login target.
        if (!$user || !empty($user['is_demo']) || !User::verifyPassword($password, $user['password_hash'])) {
            Response::json(['error' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'], 401);
        }

        // With 2FA on, the password alone does not open a session: the user id
        // is parked as a pending challenge until a valid code arrives.
        if (TwoFactor::isEnabled((int)$user['id'])) {
            session_regenerate_id(true);
            $_SESSION['pending_2fa_user'] = (int)$user['id'];
            $_SESSION['pending_2fa_since'] = time();
            $_SESSION['pending_2fa_remember'] = $remember;
            Response::json(['ok' => true, 'two_factor_required' => true]);
        }

        unset($_SESSION['app_share_token']);
        Auth::login($user);
        if ($remember) RememberToken::issue((int)$user['id']);
        RateLimiter::clear($rateKey);
        Response::json(['ok' => true, 'redirect' => APP_URL . '/']);
    }

    /**
     * POST /api/auth/two-factor — second step of a login.
     *
     * Only reachable while a password challenge is pending, and that pending
     * state expires so an abandoned half-login cannot be resumed later.
     */
    public function apiTwoFactor(): void
    {
        $pendingUserId = (int)($_SESSION['pending_2fa_user'] ?? 0);
        $since         = (int)($_SESSION['pending_2fa_since'] ?? 0);

        if ($pendingUserId < 1 || $since < 1 || (time() - $since) > 300) {
            unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_since'], $_SESSION['pending_2fa_remember']);
            Response::json(['error' => 'หมดเวลายืนยันตัวตน กรุณาเข้าสู่ระบบใหม่'], 401);
        }

        // Brute force here is guessing a six-digit code, so the limit is tight.
        $rateKey = '2fa:' . $pendingUserId . ':' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
        if (!RateLimiter::hit($rateKey, 8, 900)) {
            Response::json(['error' => 'ลองยืนยันตัวตนมากเกินไป กรุณารอประมาณ 15 นาที'], 429);
        }

        $code = (string)Request::rawInput('code', '');
        if ($code === '') {
            Response::json(['error' => 'กรุณากรอกรหัสยืนยัน'], 422);
        }

        if (!TwoFactor::verifyChallenge($pendingUserId, $code)) {
            Response::json(['error' => 'รหัสยืนยันไม่ถูกต้อง'], 401);
        }

        $user = User::findById($pendingUserId);
        if (!$user) {
            Response::json(['error' => 'ไม่พบบัญชีผู้ใช้'], 401);
        }

        $remember = !empty($_SESSION['pending_2fa_remember']);
        unset($_SESSION['pending_2fa_user'], $_SESSION['pending_2fa_since'], $_SESSION['pending_2fa_remember']);
        unset($_SESSION['app_share_token']);

        Auth::login($user);
        if ($remember) RememberToken::issue((int)$user['id']);
        RateLimiter::clear($rateKey);
        RateLimiter::clear('login:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));

        Response::json(['ok' => true, 'redirect' => APP_URL . '/']);
    }

    public function apiRegister(): void
    {
        $username    = trim(Request::rawInput('username',     ''));
        $email       = trim(Request::rawInput('email',        ''));
        $password    = Request::rawInput('password',          '');
        $confirm     = Request::rawInput('confirm_password',  '');
        $displayName = trim(Request::rawInput('display_name', ''));

        // Validate
        if (!$username || !$email || !$password) {
            Response::json(['error' => 'กรุณากรอกข้อมูลให้ครบ'], 422);
        }
        if (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            Response::json(['error' => 'ชื่อผู้ใช้ต้องเป็นภาษาอังกฤษ ตัวเลข หรือ _ (3-30 ตัว)'], 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            Response::json(['error' => 'รูปแบบอีเมลไม่ถูกต้อง'], 422);
        }
        if (strlen($password) < 8) {
            Response::json(['error' => 'รหัสผ่านต้องมีอย่างน้อย 8 ตัวอักษร'], 422);
        }
        if ($password !== $confirm) {
            Response::json(['error' => 'รหัสผ่านไม่ตรงกัน'], 422);
        }
        if (User::usernameExists($username)) {
            Response::json(['error' => 'ชื่อผู้ใช้นี้ถูกใช้งานแล้ว'], 422);
        }
        if (User::emailExists($email)) {
            Response::json(['error' => 'อีเมลนี้ถูกใช้งานแล้ว'], 422);
        }

        $userId = User::create($username, $email, $password, $displayName ?: $username);
        $user   = User::findById($userId);
        unset($_SESSION['app_share_token']);
        Auth::login($user);
        Response::json(['ok' => true, 'redirect' => APP_URL . '/'], 201);
    }

    public function apiLogout(): void
    {
        Auth::logout();
        Response::json(['ok' => true]);
    }

    public function apiGoogleLogin(): void
    {
        $credential = Request::rawInput('credential', '');

        if (!$credential) {
            Response::json(['error' => 'กรุณาส่ง Google credential'], 400);
        }

        // Verify Google Token using Google's secure tokeninfo API
        $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !$response) {
            Response::json(['error' => 'ไม่สามารถยืนยันตัวตนกับ Google ได้ หรือ Token หมดอายุ'], 400);
        }

        $payload = json_decode($response, true);

        // Check Client ID matching
        if (($payload['aud'] ?? '') !== GOOGLE_CLIENT_ID) {
            Response::json(['error' => 'Client ID ไม่ตรงกัน'], 400);
        }

        $email = trim($payload['email'] ?? '');
        $emailVerified = $payload['email_verified'] ?? false;
        $name = trim($payload['name'] ?? '');
        $picture = trim($payload['picture'] ?? '');

        if (!$email || !$emailVerified) {
            Response::json(['error' => 'อีเมลจาก Google ไม่ถูกต้องหรือไม่ได้รับการยืนยัน'], 400);
        }

        // Check if user already exists
        $user = User::findByEmail($email);

        if ($user && !empty($user['is_demo'])) {
            Response::json(['error' => 'อีเมลนี้ถูกใช้งานแล้ว'], 422);
        }

        if (!$user) {
            // Register a new user
            $emailParts = explode('@', $email);
            $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $emailParts[0]);
            
            if (strlen($prefix) < 3) {
                $prefix = 'user_' . $prefix;
            }
            $prefix = substr($prefix, 0, 20); // Leave space for random suffix

            $username = $prefix;
            $counter = 1;
            while (User::usernameExists($username)) {
                $suffix = '_' . $counter;
                $username = substr($prefix, 0, 30 - strlen($suffix)) . $suffix;
                $counter++;
            }

            // Generate secure random password
            $password = bin2hex(random_bytes(16));
            
            try {
                $userId = User::create($username, $email, $password, $name ?: $username);
                $user = User::findById($userId);
            } catch (\Throwable $e) {
                error_log($e->getMessage());
                Response::json(['error' => 'เกิดข้อผิดพลาดในการลงทะเบียน'], 500);
            }
        }

        unset($_SESSION['app_share_token']);
        Auth::login($user);

        // Update picture as avatar if available
        if ($picture) {
            User::updateAvatar($user['id'], $picture);
        }

        Response::json(['ok' => true, 'redirect' => APP_URL . '/']);
    }
}
