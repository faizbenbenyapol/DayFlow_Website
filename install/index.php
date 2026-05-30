<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ติดตั้งระบบ</title>
    <link href="https://fonts.googleapis.com/css2?family=Sarabun:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Sarabun', sans-serif; background: #f7f7f7; min-height: 100vh; display: flex; align-items: flex-start; justify-content: center; padding: 3rem 1rem; }
        .card { background: #fff; border: 1px solid #e0e0e0; border-radius: 10px; padding: 2.5rem; width: 100%; max-width: 560px; }
        h1 { font-size: 1.4rem; font-weight: 600; margin-bottom: 0.5rem; }
        .subtitle { color: #6b6b6b; font-size: 0.9rem; margin-bottom: 2rem; }
        .form-group { margin-bottom: 1.2rem; }
        label { display: block; font-size: 0.85rem; font-weight: 500; margin-bottom: 0.4rem; }
        input { width: 100%; padding: 0.6rem 0.9rem; border: 1px solid #ccc; border-radius: 6px; font-size: 0.9rem; font-family: inherit; }
        input:focus { outline: none; border-color: #1a1a1a; }
        .btn { display: inline-block; padding: 0.6rem 1.4rem; background: #1a1a1a; color: #fff; border: none; border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-family: inherit; margin-top: 1rem; width: 100%; }
        .btn:hover { opacity: 0.85; }
        .alert { padding: 0.75rem 1rem; border-radius: 6px; font-size: 0.875rem; margin-bottom: 1.2rem; }
        .alert-danger { background: #fdf0ef; color: #c0392b; border: 1px solid #e8b4b0; }
        .alert-success { background: #edfaf3; color: #27ae60; border: 1px solid #b0dfc3; }
        .section-title { font-size: 0.8rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: #6b6b6b; margin: 1.5rem 0 1rem; padding-top: 1.5rem; border-top: 1px solid #e0e0e0; }
        code { background: #f7f7f7; padding: 2px 6px; border-radius: 4px; font-size: 0.85rem; }
        .hint { font-size: 0.8rem; color: #999; margin-top: 0.25rem; }
        hr { border: none; border-top: 1px solid #e0e0e0; margin: 1.5rem 0; }
    </style>
</head>
<body>
<div class="card">
    <h1>ติดตั้งระบบจัดการชีวิต</h1>
    <p class="subtitle">กรอกข้อมูลด้านล่างเพื่อตั้งค่าระบบครั้งแรก</p>

<?php
$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost    = trim($_POST['db_host']    ?? 'localhost');
    $dbName    = trim($_POST['db_name']    ?? '');
    $dbUser    = trim($_POST['db_user']    ?? '');
    $dbPass    =      $_POST['db_pass']    ?? '';
    $appUrl    = rtrim(trim($_POST['app_url']    ?? ''), '/');
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminEmail= trim($_POST['admin_email']?? '');
    $adminPass =      $_POST['admin_pass'] ?? '';

    if (!$dbName)     $errors[] = 'กรุณากรอกชื่อ Database';
    if (!$dbUser)     $errors[] = 'กรุณากรอกชื่อผู้ใช้ Database';
    if (!$appUrl)     $errors[] = 'กรุณากรอก URL ของเว็บไซต์';
    if (!$adminUser)  $errors[] = 'กรุณากรอกชื่อผู้ใช้งาน Admin';
    if (!$adminEmail) $errors[] = 'กรุณากรอกอีเมล Admin';
    if (strlen($adminPass) < 8) $errors[] = 'รหัสผ่าน Admin ต้องมีอย่างน้อย 8 ตัวอักษร';

    if (empty($errors)) {
        // Test DB connection
        try {
            $pdo = new PDO(
                "mysql:host={$dbHost};charset=utf8mb4",
                $dbUser, $dbPass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // Create database if not exists
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbName}`");

            // Run schema
            $schemaFile = dirname(__DIR__) . '/sql/schema.sql';
            if (!file_exists($schemaFile)) {
                $errors[] = 'ไม่พบไฟล์ sql/schema.sql';
            } else {
                $sql = file_get_contents($schemaFile);
                // Remove CREATE DATABASE and USE statements (already done)
                $sql = preg_replace('/CREATE DATABASE.*?;/is', '', $sql);
                $sql = preg_replace('/USE `.*?`;/is', '', $sql);

                // Execute each statement
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if (!empty($stmt)) {
                        $pdo->exec($stmt);
                    }
                }

                // Create admin user
                $hash = password_hash($adminPass, PASSWORD_BCRYPT, ['cost' => 12]);
                $pdo->prepare('INSERT IGNORE INTO users (username, email, password_hash, display_name) VALUES (?, ?, ?, ?)')
                    ->execute([$adminUser, $adminEmail, $hash, $adminUser]);

                $userId = $pdo->lastInsertId();
                if ($userId) {
                    $pdo->prepare('INSERT IGNORE INTO user_settings (user_id, theme, timezone) VALUES (?, "light", "Asia/Bangkok")')
                        ->execute([$userId]);
                }

                // Seed finance categories
                $stmt = $pdo->prepare('INSERT IGNORE INTO finance_categories (user_id, name, type) VALUES (?, ?, ?)');
                $incomeCategories  = ['เงินเดือน', 'รายได้อื่น ๆ', 'โบนัส', 'ดอกเบี้ย', 'ขายของ'];
                $expenseCategories = ['อาหาร', 'เดินทาง', 'สาธารณูปโภค', 'ช็อปปิ้ง', 'สุขภาพ', 'บันเทิง', 'การศึกษา', 'ที่พัก', 'โทรศัพท์', 'อื่น ๆ'];
                foreach ($incomeCategories  as $cat) $stmt->execute([$userId ?: 1, $cat, 'income']);
                foreach ($expenseCategories as $cat) $stmt->execute([$userId ?: 1, $cat, 'expense']);

                // Write config file
                $configContent = '<?php
// config/config.php — Generated by Install Wizard

// --- Database ---
define(\'DB_HOST\', \'' . addslashes($dbHost) . '\');
define(\'DB_NAME\', \'' . addslashes($dbName) . '\');
define(\'DB_USER\', \'' . addslashes($dbUser) . '\');
define(\'DB_PASS\', \'' . addslashes($dbPass) . '\');

// --- Application URL ---
define(\'APP_URL\', \'' . addslashes($appUrl) . '\');

// --- Application Settings ---
define(\'APP_NAME\', \'ระบบจัดการชีวิต\');
define(\'SESSION_NAME\', \'mylife_sess\');
define(\'UPLOAD_DIR\', dirname(__DIR__) . \'/uploads/\');
define(\'UPLOAD_URL\', APP_URL . \'/uploads/\');
define(\'MAX_UPLOAD_BYTES\', 20 * 1024 * 1024);
define(\'TIMEZONE\', \'Asia/Bangkok\');

date_default_timezone_set(TIMEZONE);

// Production: disable error display
error_reporting(0);
ini_set(\'display_errors\', 0);

function h(string $s): string {
    return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, \'UTF-8\');
}
function uuid4(): string {
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf(\'%s%s-%s-%s-%s-%s%s%s\', str_split(bin2hex($data), 4));
}
function formatBytes(int $bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . \' GB\';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 2) . \' MB\';
    if ($bytes >= 1024)       return round($bytes / 1024, 2) . \' KB\';
    return $bytes . \' B\';
}
function thaiDate(string $date): string {
    if (!$date) return \'\';
    $ts = strtotime($date);
    $months = [\'ม.ค.\',\'ก.พ.\',\'มี.ค.\',\'เม.ย.\',\'พ.ค.\',\'มิ.ย.\',\'ก.ค.\',\'ส.ค.\',\'ก.ย.\',\'ต.ค.\',\'พ.ย.\',\'ธ.ค.\'];
    $d = (int)date(\'j\', $ts); $m = $months[(int)date(\'n\', $ts) - 1]; $y = (int)date(\'Y\', $ts) + 543;
    return "$d $m $y";
}
';

                file_put_contents(dirname(__DIR__) . '/config/config.php', $configContent);

                $success = 'ติดตั้งสำเร็จ! ชื่อผู้ใช้: ' . htmlspecialchars($adminUser, ENT_QUOTES, 'UTF-8');
            }
        } catch (PDOException $e) {
            $errors[] = 'เชื่อมต่อ Database ไม่สำเร็จ: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        }
    }
}
?>

<?php if ($errors): ?>
<div class="alert alert-danger">
    <?php foreach ($errors as $e): ?><div><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div><?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($success): ?>
<div class="alert alert-success">
    <strong><?= $success ?></strong><br>
    <br>
    <strong>สิ่งที่ต้องทำต่อ:</strong><br>
    1. ลบโฟลเดอร์ <code>install/</code> ออกจากเซิร์ฟเวอร์ทันที เพื่อความปลอดภัย<br>
    2. <a href="<?= htmlspecialchars($_POST['app_url'] ?? '/', ENT_QUOTES, 'UTF-8') ?>/login" style="color:#27ae60">เข้าสู่ระบบ</a>
</div>
<?php else: ?>

<form method="POST">
    <div class="section-title">ตั้งค่า Database</div>

    <div class="form-group">
        <label>Database Host</label>
        <input type="text" name="db_host" value="<?= htmlspecialchars($_POST['db_host'] ?? 'localhost', ENT_QUOTES, 'UTF-8') ?>" placeholder="localhost">
    </div>
    <div class="form-group">
        <label>Database Name</label>
        <input type="text" name="db_name" value="<?= htmlspecialchars($_POST['db_name'] ?? 'mylife_db', ENT_QUOTES, 'UTF-8') ?>" placeholder="mylife_db">
    </div>
    <div class="form-group">
        <label>Database Username</label>
        <input type="text" name="db_user" value="<?= htmlspecialchars($_POST['db_user'] ?? 'root', ENT_QUOTES, 'UTF-8') ?>" placeholder="root">
    </div>
    <div class="form-group">
        <label>Database Password</label>
        <input type="password" name="db_pass" value="" placeholder="(ว่างถ้าไม่มีรหัสผ่าน)">
    </div>

    <div class="section-title">ตั้งค่าเว็บไซต์</div>

    <div class="form-group">
        <label>URL ของเว็บไซต์</label>
        <input type="text" name="app_url" value="<?= htmlspecialchars($_POST['app_url'] ?? 'http://localhost/my', ENT_QUOTES, 'UTF-8') ?>" placeholder="http://localhost/my">
        <p class="hint">ไม่ต้องมี / ท้าย URL</p>
    </div>

    <div class="section-title">สร้างบัญชี Admin</div>

    <div class="form-group">
        <label>ชื่อผู้ใช้งาน</label>
        <input type="text" name="admin_user" value="<?= htmlspecialchars($_POST['admin_user'] ?? 'admin', ENT_QUOTES, 'UTF-8') ?>" placeholder="admin">
    </div>
    <div class="form-group">
        <label>อีเมล</label>
        <input type="email" name="admin_email" value="<?= htmlspecialchars($_POST['admin_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="admin@example.com">
    </div>
    <div class="form-group">
        <label>รหัสผ่าน (อย่างน้อย 8 ตัวอักษร)</label>
        <input type="password" name="admin_pass" placeholder="••••••••">
    </div>

    <button type="submit" class="btn">ติดตั้งระบบ</button>
</form>

<?php endif; ?>

</div>
</body>
</html>
