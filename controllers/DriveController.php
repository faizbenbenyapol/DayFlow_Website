<?php
// =====================================================
// controllers/DriveController.php — เชื่อม/ยกเลิก Google Drive และย้ายไฟล์เดิม (เฟส 4 REQ-20, REQ-22)
//
// /settings/drive/connect → หน้าขออนุญาตของ Google → /settings/drive/callback → กลับหน้าตั้งค่า #drive
// /api/drive* ใช้โดย public/assets/js/settings-drive.js (สถานะ, ย้ายต่อ, ลองต่อหลัง Drive เต็ม, ยกเลิก)
// =====================================================

require_once ROOT . '/core/DriveMigration.php';

class DriveController
{
    /** ชื่อโฟลเดอร์ที่ DayFlow สร้างใน Drive ของผู้ใช้ (CR-1: ยังไม่ใช้ Google Picker) */
    private const FOLDER_NAME = 'DayFlow';
    /** หน้าตั้งค่าเรียกย้ายครั้งละไม่เกินนี้ (วินาที) จะได้เห็นความคืบหน้าบ่อย ๆ */
    private const STEP_SECONDS = 20;

    /** พาไปหน้าขออนุญาตของ Google พร้อม state ที่ผูกกับ session นี้ (กันคนอื่นปลอมคำตอบกลับมา) */
    public function connect(): never
    {
        if (!DriveClient::isConfigured()) Response::abort(404, 'ยังไม่ได้เปิดใช้ Google Drive');
        $_SESSION['drive_oauth_state'] = bin2hex(random_bytes(16));
        header('Location: ' . DriveClient::authUrl($_SESSION['drive_oauth_state']));
        exit;
    }

    /**
     * Google ส่งผู้ใช้กลับมาพร้อม code: แลก token, หาอีเมล, เตรียมโฟลเดอร์ DayFlow แล้วบันทึกการเชื่อม
     * เชื่อมกลับบัญชีเดิมใช้โฟลเดอร์เดิม ไฟล์ที่อยู่ใน Drive จึงกลับมาเปิดได้ บัญชีอื่นถูกปฏิเสธถ้ามีไฟล์ใน Drive เดิม
     * จบด้วยการพากลับหน้าตั้งค่า ?drive=ผลลัพธ์ ให้หน้าเว็บบอกผู้ใช้
     */
    public function callback(): never
    {
        $expected = (string)($_SESSION['drive_oauth_state'] ?? '');
        unset($_SESSION['drive_oauth_state']);
        if ($expected === '' || !hash_equals($expected, (string)Request::query('state', ''))) self::backToSettings('failed');
        if (Request::query('error', '') !== '' || Request::query('code', '') === '') self::backToSettings('denied');

        $userId = Auth::userId();
        $previous = DriveLink::find($userId);
        try {
            $tokens = DriveClient::exchangeCode((string)Request::query('code'));
            $client = new DriveClient($tokens['refresh_token'], $tokens['access_token'], $tokens['expires_at'], fn() => null);
            $email = $client->email();
            $sameAccount = $previous !== null && strcasecmp($previous['google_email'], $email) === 0;
            if ($previous !== null && !$sameAccount && self::hasFilesInDrive($userId)) {
                DriveClient::revoke($tokens['refresh_token']);
                self::backToSettings('other-account');
            }
            $folderId = $sameAccount ? (string)$previous['folder_id'] : $client->createFolder(self::FOLDER_NAME, 'root');
        } catch (DriveError) {
            self::backToSettings('failed');
        }

        $hasLocalFiles = DriveMigration::status($userId)['pending'] > 0;
        DriveLink::connect($userId, $email, $tokens, $folderId, self::FOLDER_NAME, $hasLocalFiles ? 'migrating' : 'connected');
        self::backToSettings('connected');
    }

    /** สถานะสำหรับหน้าตั้งค่า */
    public function apiStatus(): void
    {
        Response::json(self::statusPayload(Auth::userId()));
    }

    /** ย้ายไฟล์เดิมต่ออีกช่วงหนึ่ง หน้าตั้งค่าเรียกซ้ำจนไม่เหลือ (ปิดหน้าไปแล้ว cron ทำต่อ) */
    public function apiMigrate(): void
    {
        DriveMigration::step(Auth::userId(), self::STEP_SECONDS);
        Response::json(self::statusPayload(Auth::userId()));
    }

    /** ลองย้ายต่อหลัง Drive เต็ม (EDGE-12) เมื่อผู้ใช้เพิ่มพื้นที่แล้ว */
    public function apiResume(): void
    {
        $userId = Auth::userId();
        DriveLink::setState($userId, ['paused_full'], 'migrating');
        DriveMigration::step($userId, self::STEP_SECONDS);
        Response::json(self::statusPayload($userId));
    }

    /** ยกเลิกการเชื่อม: ไฟล์ใหม่กลับไปเก็บบนเซิร์ฟเวอร์ ไฟล์ใน Drive อยู่ครบและกลับมาเมื่อเชื่อมบัญชีเดิม */
    public function apiDisconnect(): void
    {
        $userId = Auth::userId();
        $link = DriveLink::find($userId);
        if ($link && $link['refresh_token_enc']) DriveClient::revoke(appDecrypt((string)$link['refresh_token_enc']));
        if ($link) DriveLink::disconnect($userId);
        Response::json(self::statusPayload($userId));
    }

    /** สิ่งที่หน้าตั้งค่าแสดง: เปิดใช้ไหม สถานะ อีเมล โฟลเดอร์ และความคืบหน้าการย้าย (ไม่มี token) */
    private static function statusPayload(int $userId): array
    {
        $link = DriveLink::find($userId);
        return DriveMigration::status($userId) + [
            'configured' => DriveClient::isConfigured(),
            'email'      => $link['google_email'] ?? null,
            'folder'     => $link['folder_name'] ?? null,
            'error'      => $link['last_error'] ?? null,
        ];
    }

    /** มีแถวไหนของผู้ใช้อยู่ใน Drive แล้วบ้าง ใช้กันการเชื่อมบัญชี Google อื่นทับ */
    private static function hasFilesInDrive(int $userId): bool
    {
        return (bool)DB::run('SELECT 1 FROM files WHERE user_id = ? AND drive_id IS NOT NULL LIMIT 1', [$userId])->fetch();
    }

    /** กลับหน้าตั้งค่า > Google Drive พร้อมผลให้ JS แสดงข้อความ (?drive=connected|denied|failed|other-account) */
    private static function backToSettings(string $result): never
    {
        Response::redirect('/settings?drive=' . $result . '#drive');
    }
}
