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
}
