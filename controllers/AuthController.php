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

    public function apiLogin(): void
    {
        $identifier = Request::rawInput('identifier', ''); // username or email
        $password   = Request::rawInput('password',   '');

        if (!$identifier || !$password) {
            Response::json(['error' => 'กรุณากรอกข้อมูลให้ครบ'], 422);
        }

        // Try email first, then username
        $user = User::findByEmail($identifier) ?? User::findByUsername($identifier);

        if (!$user || !User::verifyPassword($password, $user['password_hash'])) {
            Response::json(['error' => 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง'], 401);
        }

        unset($_SESSION['app_share_token']);
        Auth::login($user);
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
                Response::json(['error' => 'เกิดข้อผิดพลาดในการลงทะเบียน: ' . $e->getMessage()], 500);
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
