<?php
// =====================================================
// models/DriveLink.php — การเชื่อม Google Drive ของผู้ใช้หนึ่งคน (ตาราง drive_links)
//
// เป็นเจ้าของ state เดียวของการเชื่อม: token (เข้ารหัส), อีเมล, โฟลเดอร์ DayFlow ใน Drive
// และสถานะ ส่วนอื่นอ่านผ่านที่นี่ ไม่ query ตารางเอง ใช้โดย FileStore, DriveMigration, DriveController
// =====================================================

final class DriveLink
{
    /** สถานะที่ยังใช้ Drive ได้ (ไฟล์ใหม่ไป Drive อ่านไฟล์ใน Drive ได้) */
    public const USABLE = ['connected', 'migrating', 'paused_full'];

    /** การเชื่อมของผู้ใช้ รวมที่ยกเลิกแล้ว (ยังรู้อีเมลและโฟลเดอร์เดิม) หรือ null ถ้าไม่เคยเชื่อม */
    public static function find(int $userId): ?array
    {
        return DB::run('SELECT * FROM drive_links WHERE user_id = ?', [$userId])->fetch() ?: null;
    }

    /** ใช้ Drive ได้ตอนนี้ไหม: เปิดความสามารถไว้ มีการเชื่อม และสิทธิ์ยังไม่หลุดหรือถูกยกเลิก */
    public static function isUsable(?array $link): bool
    {
        return $link !== null && DriveClient::isConfigured() && in_array($link['state'], self::USABLE, true);
    }

    /**
     * บันทึกการเชื่อมใหม่หรือเชื่อมกลับ token เก่า (ถ้ามี) ถูกแทนที่
     * @param array{refresh_token: string, access_token: string, expires_at: int} $tokens จาก DriveClient::exchangeCode
     * @param string $state 'migrating' เมื่อมีไฟล์บนเซิร์ฟเวอร์ต้องย้าย ไม่งั้น 'connected'
     */
    public static function connect(int $userId, string $email, array $tokens, string $folderId, string $folderName, string $state): void
    {
        DB::run(
            'INSERT INTO drive_links (user_id, google_email, refresh_token_enc, access_token_enc, access_expires_at, folder_id, folder_name, state, last_error)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL)
             ON DUPLICATE KEY UPDATE google_email = VALUES(google_email), refresh_token_enc = VALUES(refresh_token_enc),
               access_token_enc = VALUES(access_token_enc), access_expires_at = VALUES(access_expires_at),
               folder_id = VALUES(folder_id), folder_name = VALUES(folder_name), state = VALUES(state), last_error = NULL',
            [$userId, $email, appEncrypt($tokens['refresh_token']), appEncrypt($tokens['access_token']), $tokens['expires_at'], $folderId, $folderName, $state]
        );
    }

    /**
     * DriveClient ของการเชื่อมนี้ access token ใหม่ที่ได้ระหว่างใช้ถูกบันทึกกลับเอง
     * @throws DriveError AUTH เมื่อการเชื่อมใช้ไม่ได้ (ยกเลิกแล้ว สิทธิ์หลุด หรือปิดความสามารถไว้)
     */
    public static function client(?array $link): DriveClient
    {
        if (!self::isUsable($link)) throw new DriveError(DriveError::AUTH, 'Google Drive is not connected');
        $userId = (int)$link['user_id'];
        return new DriveClient(
            appDecrypt((string)$link['refresh_token_enc']),
            $link['access_token_enc'] ? appDecrypt((string)$link['access_token_enc']) : null,
            (int)$link['access_expires_at'],
            function (string $token, int $expiresAt) use ($userId): void {
                // ยกเลิกการเชื่อมไปแล้วระหว่างทาง (refresh token ถูกลบ) ไม่เก็บ token ใหม่กลับเข้าไป
                DB::run('UPDATE drive_links SET access_token_enc = ?, access_expires_at = ? WHERE user_id = ? AND refresh_token_enc IS NOT NULL', [appEncrypt($token), $expiresAt, $userId]);
            },
        );
    }

    /**
     * เปลี่ยนสถานะเฉพาะเมื่อสถานะตอนนี้อยู่ใน $from เช่น migrating → connected, paused_full (EDGE-12), revoked (EDGE-11)
     * เงื่อนไขอยู่ใน UPDATE เดียวกัน: การย้ายที่ทำงานค้างอยู่จึงเขียนทับ "ยกเลิกการเชื่อม" ที่ผู้ใช้เพิ่งกดไม่ได้
     * @param list<string> $from
     */
    public static function setState(int $userId, array $from, string $state, ?string $error = null): void
    {
        $marks = implode(',', array_fill(0, count($from), '?'));
        DB::run("UPDATE drive_links SET state = ?, last_error = ? WHERE user_id = ? AND state IN ({$marks})", [$state, $error, $userId, ...$from]);
    }

    /** ยกเลิกการเชื่อมฝั่ง DayFlow: ลบ token เก็บอีเมลกับโฟลเดอร์ไว้ ไฟล์ใน Drive ไม่ถูกแตะ */
    public static function disconnect(int $userId): void
    {
        DB::run(
            'UPDATE drive_links SET refresh_token_enc = NULL, access_token_enc = NULL, access_expires_at = 0, state = "disconnected", last_error = NULL WHERE user_id = ?',
            [$userId]
        );
    }

    /** ผู้ใช้ที่ยังมีไฟล์บนเซิร์ฟเวอร์รอย้าย ให้ cron ทำต่อ (EDGE-13) @return list<int> */
    public static function migratingUserIds(): array
    {
        return array_map('intval', array_column(DB::run('SELECT user_id FROM drive_links WHERE state = "migrating"')->fetchAll(), 'user_id'));
    }
}
