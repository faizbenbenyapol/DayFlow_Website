<?php
// =====================================================
// models/FileTransfer.php — ส่งไฟล์ด้วยรหัส 6 หลัก (FileTransferController และ cron.php ใช้)
//
// ส่งหนึ่งครั้ง = หนึ่งแถว: ไฟล์อัปโหลดทีละชิ้นระหว่าง status 'uploading' แล้วเป็น 'ready'
// เมื่อครบ ระหว่างอัปโหลด expires_at คือเส้นตายของการอัปโหลดที่ค้าง (ขยับทุกชิ้น)
// พอเสร็จจึงเป็นเวลาหมดอายุจริง แถวที่เลย expires_at ถูก cleanup() ลบพร้อมไฟล์
// =====================================================

class FileTransfer
{
    /** ขนาดรวมสูงสุดต่อการส่งหนึ่งครั้ง (REQ-16): 10 × 1024³ ไบต์ */
    public const MAX_TOTAL_BYTES = 10 * 1024 ** 3;
    /** ZIP ทั้งหมดได้เมื่อรวมไม่เกินนี้ เกินนั้นดาวน์โหลดทีละไฟล์ (ผู้ใช้ตัดสินตอนเริ่มเฟส 3) */
    public const MAX_ZIP_BYTES = 2 * 1024 ** 3;
    /** ขนาดชิ้นอัปโหลด: ต้องเล็กกว่า post_max_size ของ PHP (22 MB) และขีดของ Cloudflare (~100 MB) */
    public const CHUNK_BYTES = 8 * 1024 * 1024;
    /** เวลาหมดอายุที่เลือกได้ (นาที) ตัวแรกคือค่าเริ่มต้น (REQ-19) */
    public const EXPIRY_CHOICES = [5, 15, 30, 60];
    public const MAX_MESSAGE_CHARS = 10000;
    /** การอัปโหลดที่ไม่มีชิ้นใหม่นานเท่านี้ถือว่าค้าง cron ทุก 5 นาทีจึงลบได้ภายใน 1 ชั่วโมง (EDGE-7) */
    private const STALE_MINUTES = 50;
    /** เผื่อพื้นที่ดิสก์ไว้ให้ส่วนอื่นของแอป ก่อนรับการส่งใหม่ (EDGE-8) */
    private const DISK_MARGIN_BYTES = 512 * 1024 * 1024;

    /**
     * ตรวจคำขอเริ่มส่งก่อนรับไบต์แรก (EDGE-6, EDGE-8, EDGE-9)
     * @param list<array{name: string, size: int}> $files ไฟล์ที่จะส่ง ผ่าน cleanFiles() แล้ว
     * @return array{0: int, 1: string}|null [HTTP status, ข้อความภาษาไทย] หรือ null ถ้าส่งได้
     */
    public static function startError(array $files, string $message, int $expiryMinutes): ?array
    {
        if ($files === [] && trim($message) === '') return [422, 'เลือกไฟล์ หรือพิมพ์ข้อความที่จะส่งก่อน'];
        if (mb_strlen($message) > self::MAX_MESSAGE_CHARS) return [422, 'ข้อความยาวได้ไม่เกิน 10,000 ตัวอักษร'];
        if (!in_array($expiryMinutes, self::EXPIRY_CHOICES, true)) return [422, 'เลือกเวลาหมดอายุ 5, 15, 30 หรือ 60 นาที'];

        $total = array_sum(array_column($files, 'size'));
        if ($total > self::MAX_TOTAL_BYTES) return [422, 'ไฟล์รวมกันเกิน 10 GB ลองแบ่งส่งหลายครั้ง'];
        $free = @disk_free_space(UPLOAD_DIR);
        if ($free !== false && $total + self::DISK_MARGIN_BYTES > $free) return [507, 'พื้นที่เซิร์ฟเวอร์ไม่พอสำหรับไฟล์ชุดนี้ ลองส่งไฟล์ให้น้อยลง'];
        return null;
    }

    /**
     * แปลงรายการไฟล์ที่เบราว์เซอร์ประกาศมาให้เหลือรูปแบบที่เชื่อได้
     * @param array $declared [{name, size}, ...] จากคำขอ
     * @return list<array{name: string, size: int}>|null null เมื่อรูปแบบผิด (ขนาดติดลบ ไม่ใช่ตัวเลข)
     */
    public static function cleanFiles(array $declared): ?array
    {
        $files = [];
        foreach ($declared as $file) {
            if (!is_array($file) || !isset($file['size']) || !is_numeric($file['size']) || (int)$file['size'] < 0) return null;
            // ชื่อใช้แค่ตอนดาวน์โหลด ไฟล์บนดิสก์ใช้ชื่อสุ่ม ตัด path และตัวควบคุมออก
            $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', (string)($file['name'] ?? '')))) ?? '';
            $files[] = ['name' => mb_substr($name !== '' ? $name : 'file', 0, 255), 'size' => (int)$file['size']];
        }
        return $files;
    }

    /**
     * เริ่มการส่ง: สร้างแถวและไฟล์เปล่าบนดิสก์ ส่งแค่ข้อความจะพร้อมใช้ทันที
     * @param list<array{name: string, size: int}> $files ผ่าน startError() แล้ว
     * @return array แถวใน file_transfers
     */
    public static function start(int $userId, array $files, string $message, int $expiryMinutes): array
    {
        $dir = 'transfers/' . $userId . '/' . date('Ymd') . '_' . bin2hex(random_bytes(8));
        if ($files !== [] && !is_dir(UPLOAD_DIR . $dir)) mkdir(UPLOAD_DIR . $dir, 0755, true);

        $stored = [];
        foreach ($files as $file) {
            $path = $dir . '/' . bin2hex(random_bytes(8));
            touch(UPLOAD_DIR . $path);
            $stored[] = ['name' => $file['name'], 'path' => $path, 'size' => $file['size']];
        }

        $ready = $files === [];
        $minutes = $ready ? $expiryMinutes : self::STALE_MINUTES;
        DB::run(
            'INSERT INTO file_transfers (user_id, code, token, files_json, message, status, expiry_minutes, total_size, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)',
            [$userId, self::generateUniqueCode(), bin2hex(random_bytes(32)), json_encode($stored, JSON_UNESCAPED_UNICODE),
             $message === '' ? null : $message, $ready ? 'ready' : 'uploading', $expiryMinutes,
             array_sum(array_column($files, 'size')), $minutes]
        );
        return self::getById((int)DB::conn()->lastInsertId());
    }

    /** การส่งที่ยังอัปโหลดอยู่ของผู้ใช้คนนี้ ของคนอื่นหรือที่เสร็จแล้วได้ null เหมือนไม่มี */
    public static function findUploading(int $id, int $userId): ?array
    {
        $row = DB::run(
            'SELECT * FROM file_transfers WHERE id = ? AND user_id = ? AND status = "uploading" AND expires_at > NOW()',
            [$id, $userId]
        )->fetch();
        return $row ?: null;
    }

    /**
     * เขียนชิ้นหนึ่งลงตำแหน่ง offset ของไฟล์ ส่งชิ้นเดิมซ้ำได้ผลเท่าเดิม (ใช้ตอนลองใหม่หลังเน็ตหลุด)
     * ผลข้างเคียง: เขียนไฟล์บนดิสก์ และเลื่อนเส้นตายของการอัปโหลดค้างออกไป
     * @return string|null ข้อความ error ภาษาไทย หรือ null เมื่อเขียนสำเร็จ
     */
    public static function writeChunk(array $transfer, int $index, int $offset, string $bytes): ?string
    {
        $files = json_decode($transfer['files_json'], true) ?: [];
        if (!isset($files[$index])) return 'ไม่มีไฟล์ลำดับนี้ในการส่ง';
        if (strlen($bytes) > self::CHUNK_BYTES) return 'ชิ้นใหญ่เกินไป';

        $path = UPLOAD_DIR . $files[$index]['path'];
        clearstatcache(true, $path);
        $current = is_file($path) ? filesize($path) : 0;
        // เขียนต่อจากที่มี หรือทับชิ้นที่เคยส่งแล้วได้ แต่ข้ามไปข้างหน้าไม่ได้ ไฟล์จะมีช่องว่างเป็นไบต์ศูนย์
        if ($offset < 0 || $offset > $current) return 'ชิ้นนี้มาก่อนชิ้นก่อนหน้า';
        if ($offset + strlen($bytes) > (int)$files[$index]['size']) return 'ชิ้นนี้เกินขนาดไฟล์ที่แจ้งไว้';

        $handle = fopen($path, 'c');   // 'c' เปิดโดยไม่ล้างเนื้อเดิม
        if ($handle === false) return 'บันทึกชิ้นไม่สำเร็จ';
        $written = fseek($handle, $offset) === 0 ? fwrite($handle, $bytes) : false;
        fclose($handle);
        if ($written !== strlen($bytes)) return 'บันทึกชิ้นไม่สำเร็จ';

        DB::run('UPDATE file_transfers SET expires_at = NOW() + INTERVAL ? MINUTE WHERE id = ?', [self::STALE_MINUTES, $transfer['id']]);
        return null;
    }

    /**
     * ปิดการอัปโหลด: ทุกไฟล์ต้องครบขนาดที่แจ้งไว้ แล้วเริ่มนับเวลาหมดอายุจากตอนนี้ (REQ-19)
     * @return string|null ข้อความ error (ไฟล์ไหนยังมาไม่ครบ) หรือ null เมื่อพร้อมให้รับ
     */
    public static function finish(array $transfer): ?string
    {
        foreach (json_decode($transfer['files_json'], true) ?: [] as $file) {
            $path = UPLOAD_DIR . $file['path'];
            clearstatcache(true, $path);
            if (!is_file($path) || filesize($path) !== (int)$file['size']) return 'ไฟล์ "' . $file['name'] . '" ยังมาไม่ครบ';
        }
        DB::run(
            'UPDATE file_transfers SET status = "ready", expires_at = NOW() + INTERVAL expiry_minutes MINUTE WHERE id = ?',
            [$transfer['id']]
        );
        return null;
    }

    /** หลายไฟล์และรวมไม่เกิน 2 GB จึงมีปุ่ม ZIP ทั้งหมด @param list<array{size: int|string}> $files */
    public static function canZip(array $files): bool
    {
        return count($files) > 1 && array_sum(array_map(fn(array $f): int => (int)$f['size'], $files)) <= self::MAX_ZIP_BYTES;
    }

    /** รหัส 6 หลักที่ไม่ชนกับการส่งที่ยังไม่หมดอายุ (รวมที่กำลังอัปโหลด) */
    private static function generateUniqueCode(): string
    {
        for ($i = 0; $i < 50; $i++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $exists = DB::run('SELECT id FROM file_transfers WHERE code = ? AND expires_at > NOW()', [$code])->fetch();
            if (!$exists) return $code;
        }
        throw new \RuntimeException('Cannot generate unique transfer code');
    }

    public static function getById(int $id): ?array
    {
        $row = DB::run('SELECT * FROM file_transfers WHERE id = ?', [$id])->fetch();
        return $row ?: null;
    }

    /** การส่งจากรหัส 6 หลัก (ยังไม่หมดอายุ) ต้องผ่าน isValid() ก่อนให้ใครดู */
    public static function getByCode(string $code): ?array
    {
        $row = DB::run('SELECT * FROM file_transfers WHERE code = ? AND expires_at > NOW()', [$code])->fetch();
        return $row ?: null;
    }

    /** การส่งจาก token ของลิงก์ ต้องผ่าน isValid() ก่อนให้ใครดู */
    public static function getByToken(string $token): ?array
    {
        $row = DB::run('SELECT * FROM file_transfers WHERE token = ?', [$token])->fetch();
        return $row ?: null;
    }

    /** รับได้ไหม: อัปโหลดเสร็จแล้ว ยังไม่หมดอายุ และยังไม่เกินจำนวนดาวน์โหลดที่จำกัด */
    public static function isValid(?array $transfer): bool
    {
        if (!$transfer || $transfer['status'] !== 'ready') return false;
        if (strtotime($transfer['expires_at']) <= time()) return false;
        if ($transfer['max_downloads'] > 0 && $transfer['download_count'] >= $transfer['max_downloads']) return false;
        return true;
    }

    public static function incrementDownload(int $id): void
    {
        DB::run('UPDATE file_transfers SET download_count = download_count + 1 WHERE id = ?', [$id]);
    }

    /** ประวัติการส่งที่เสร็จแล้วของผู้ใช้ ใหม่สุดก่อน (ที่กำลังอัปโหลดอยู่ไม่นับ) */
    public static function listByUser(int $userId): array
    {
        return DB::run(
            'SELECT id, code, token, files_json, message, total_size, download_count, max_downloads, expires_at, created_at
             FROM file_transfers
             WHERE user_id = ? AND status = "ready"
             ORDER BY created_at DESC
             LIMIT 50',
            [$userId]
        )->fetchAll();
    }

    /** ลบการส่งของผู้ใช้พร้อมไฟล์บนดิสก์ ของคนอื่นได้ false เหมือนไม่มี */
    public static function delete(int $id, int $userId): bool
    {
        $transfer = DB::run('SELECT * FROM file_transfers WHERE id = ? AND user_id = ?', [$id, $userId])->fetch();
        if (!$transfer) return false;
        self::deleteFiles($transfer);
        DB::run('DELETE FROM file_transfers WHERE id = ?', [$id]);
        return true;
    }

    /**
     * ลบการส่งที่เลย expires_at พร้อมไฟล์: ทั้งที่หมดอายุแล้ว และที่อัปโหลดค้างไม่มีชิ้นใหม่ (EDGE-7)
     * เรียกจาก cron.php ทุก 5 นาที และตอนเปิดหน้าส่งไฟล์
     * @return int จำนวนแถวที่ลบ
     */
    public static function cleanup(): int
    {
        $expired = DB::run('SELECT * FROM file_transfers WHERE expires_at <= NOW()')->fetchAll();
        if ($expired === []) return 0;
        foreach ($expired as $transfer) {
            self::deleteFiles($transfer);
        }
        // ลบตาม id ที่ลบไฟล์ไปแล้ว ไม่ใช่ตามเวลาอีกรอบ: แถวที่หมดอายุระหว่างสองคำสั่งจะได้ไม่ถูกลบทั้งที่ไฟล์ยังอยู่
        $ids = array_map(fn(array $t): int => (int)$t['id'], $expired);
        return DB::run('DELETE FROM file_transfers WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids)->rowCount();
    }

    /** ลบไฟล์ของการส่งหนึ่งครั้งออกจากดิสก์ แล้วลบโฟลเดอร์ถ้าว่าง */
    private static function deleteFiles(array $transfer): void
    {
        $files = json_decode($transfer['files_json'], true) ?: [];
        foreach ($files as $f) {
            if (!empty($f['path']) && is_file(UPLOAD_DIR . $f['path'])) @unlink(UPLOAD_DIR . $f['path']);
        }
        if (!empty($files[0]['path']) && is_dir(UPLOAD_DIR . dirname($files[0]['path']))) {
            @rmdir(UPLOAD_DIR . dirname($files[0]['path']));
        }
    }
}
