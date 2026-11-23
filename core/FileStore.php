<?php
// =====================================================
// core/FileStore.php — ที่เก็บตัวไฟล์ของเมนูไฟล์: เซิร์ฟเวอร์ หรือ Google Drive ของเจ้าของ
//
// ตาราง files (FileModel) เป็นสารบัญเดียว FileStore ตัดสินว่าไบต์อยู่ที่ไหน:
//   อ่าน/แก้ของเดิม ดูจากแถว (drive_id = อยู่ใน Drive, file_path = อยู่บนเซิร์ฟเวอร์)
//   ของใหม่ ดูจากผู้ใช้ (เชื่อม Drive อยู่ → ไป Drive)
// กฎที่ต้องจริงเสมอ: แถวที่อยู่ใน Drive โฟลเดอร์แม่ทุกชั้นอยู่ใน Drive ด้วย (ensureDriveFolder)
// ทุกคำสั่งแก้ Drive ก่อน แล้วค่อยแก้ฐานข้อมูล Drive ล้มแล้วสารบัญจึงไม่ชี้ไปที่ของที่ไม่มี
// ใช้โดย FileController, ShareController และ DriveMigration (docs/decisions/2026-11-google-drive.md)
// =====================================================

require_once ROOT . '/models/File.php';
require_once ROOT . '/models/DriveLink.php';
require_once ROOT . '/core/DriveClient.php';

final class FileStore
{
    private ?array $link;
    private ?DriveClient $client = null;

    private function __construct(private int $userId)
    {
        $this->link = DriveLink::find($userId);
    }

    /** ที่เก็บของผู้ใช้คนหนึ่ง */
    public static function forUser(int $userId): self
    {
        return new self($userId);
    }

    /** ไฟล์ใหม่ของผู้ใช้คนนี้ไป Drive ไหม (เชื่อมอยู่และสิทธิ์ยังใช้ได้) */
    public function usesDrive(): bool
    {
        return DriveLink::isUsable($this->link);
    }

    /**
     * เก็บไฟล์ที่อัปโหลดมา (ผ่านการตรวจชื่อและชนิดใน FileController แล้ว) แล้วเพิ่มแถวในสารบัญ
     * @return int id ของแถวใหม่
     * @throws DriveError เมื่อเก็บใน Drive ไม่สำเร็จ (เต็ม สิทธิ์หลุด) ไม่มีแถวใหม่เกิดขึ้น
     */
    public function upload(string $tmpPath, string $name, string $mime, int $size, ?int $parentId): int
    {
        if (!$this->usesDrive()) {
            $relPath = $this->userId . '/' . date('Y-m') . '/' . uuid4() . self::extensionOf($name);
            if (!is_dir(dirname(UPLOAD_DIR . $relPath))) mkdir(dirname(UPLOAD_DIR . $relPath), 0755, true);
            if (!move_uploaded_file($tmpPath, UPLOAD_DIR . $relPath)) throw new RuntimeException('Could not store the upload');
            return FileModel::createFile($this->userId, $name, $mime, $relPath, $size, $parentId);
        }
        $stored = $this->client()->upload($tmpPath, $name, $mime, $this->ensureDriveFolder($parentId));
        if ($stored['size'] !== $size) throw new DriveError(DriveError::OTHER, 'Google Drive stored a different size');
        return FileModel::createFile($this->userId, $name, $mime, null, $size, $parentId, $stored['id']);
    }

    /** สร้างโฟลเดอร์ (ใน Drive ด้วยถ้าเชื่อมอยู่) @return int id ของแถวใหม่ */
    public function createFolder(string $name, ?int $parentId): int
    {
        $driveId = $this->usesDrive() ? $this->client()->createFolder($name, $this->ensureDriveFolder($parentId)) : null;
        return FileModel::createFolder($this->userId, $name, $parentId, $driveId);
    }

    /** เปลี่ยนชื่อใน Drive ก่อน (ถ้าแถวอยู่ใน Drive) แล้วค่อยบันทึกชื่อใหม่ใน DB @throws DriveError */
    public function rename(array $row, string $name): void
    {
        if ($row['drive_id']) $this->client()->rename((string)$row['drive_id'], $name);
        FileModel::rename((int)$row['id'], $this->userId, $name);
    }

    /**
     * ย้ายไปโฟลเดอร์อื่น (null = ราก) ของใน Drive ย้ายใน Drive ด้วย
     * @return bool false เมื่อจะย้ายโฟลเดอร์ไปไว้ในตัวเองหรือลูกของมัน
     */
    public function move(array $row, ?int $newParentId): bool
    {
        if (FileModel::wouldCreateCycle((int)$row['id'], $this->userId, $newParentId)) return false;
        if ($row['drive_id']) {
            $from = $this->ensureDriveFolder($row['parent_id'] !== null ? (int)$row['parent_id'] : null);
            $this->client()->move((string)$row['drive_id'], $from, $this->ensureDriveFolder($newParentId));
        }
        return FileModel::move((int)$row['id'], $this->userId, $newParentId);
    }

    /**
     * ลบ: ของใน Drive ย้ายลงถังขยะของ Drive (โฟลเดอร์ลงถังพร้อมลูกทั้งหมดตามกฎข้างบน)
     * แล้วลบแถวและไฟล์บนเซิร์ฟเวอร์ของแถวลูกที่ยังไม่ได้ย้าย
     */
    public function delete(array $row): void
    {
        if ($row['drive_id']) $this->client()->trash((string)$row['drive_id']);
        FileModel::delete((int)$row['id'], $this->userId);
    }

    /**
     * id ใน Drive ของโฟลเดอร์ในสารบัญ (null = โฟลเดอร์ DayFlow ใน Drive) สร้างใน Drive ให้ถ้ายังไม่มี
     * ไล่สร้างโฟลเดอร์แม่ก่อน กฎ "แม่อยู่ใน Drive ก่อนลูก" จึงจริงเสมอ ใช้ทั้งตอนอัปโหลดและตอนย้ายไฟล์เดิม
     */
    public function ensureDriveFolder(?int $folderId): string
    {
        if ($folderId === null) return (string)$this->link['folder_id'];
        $folder = FileModel::getById($folderId, $this->userId);
        if (!$folder || $folder['type'] !== 'folder') throw new RuntimeException("Folder {$folderId} is not in this account");
        if ($folder['drive_id']) return (string)$folder['drive_id'];

        $parentDriveId = $this->ensureDriveFolder($folder['parent_id'] !== null ? (int)$folder['parent_id'] : null);
        $driveId = $this->client()->createFolder((string)$folder['name'], $parentDriveId);
        FileModel::setDriveId($folderId, $driveId);
        return $driveId;
    }

    /**
     * DriveClient ของผู้ใช้ สร้างครั้งเดียวต่อคำขอ (บันทึกสถานะ revoked เป็นหน้าที่ของ noteFailure)
     * @throws DriveError AUTH เมื่อไม่ได้เชื่อม
     */
    public function client(): DriveClient
    {
        return $this->client ??= DriveLink::client($this->link);
    }

    /**
     * ส่งเนื้อไฟล์ของแถวไปที่เบราว์เซอร์ แล้วจบคำขอ ใช้ทั้งเจ้าของและลิงก์แชร์ (EDGE-14)
     * @param array $headers header ของคำตอบ (ชนิด, disposition) ผู้เรียกกำหนด ส่วน Content-Length FileStore ใส่ให้เมื่อรู้
     * ไฟล์ใน Drive ที่เปิดไม่ได้จบด้วยหน้าข้อความที่บอกเหตุ ไม่ใช่ error ดิบของ Google
     */
    public static function send(array $row, array $headers): never
    {
        if (!$row['drive_id']) {
            // path จริงต้องอยู่ใต้ uploads/ กัน file_path ที่มี ../ อ่านไฟล์อื่นของเซิร์ฟเวอร์
            $root = realpath(UPLOAD_DIR);
            $path = $row['file_path'] ? realpath(UPLOAD_DIR . $row['file_path']) : false;
            if (!$root || !$path || !is_file($path) || !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                Response::abort(404, 'ไม่พบไฟล์บนเซิร์ฟเวอร์');
            }
            self::emitHeaders($headers, filesize($path));
            readfile($path);
            exit;
        }

        $store = self::forUser((int)$row['user_id']);
        if (!$store->usesDrive()) {
            Response::abort(409, $store->link && $store->link['state'] === 'revoked'
                ? 'การเชื่อม Google Drive หลุด เจ้าของไฟล์ต้องเชื่อมใหม่ก่อนจึงจะเปิดไฟล์นี้ได้'
                : 'ไฟล์นี้อยู่ใน Google Drive ที่ยกเลิกการเชื่อมแล้ว เชื่อมบัญชีเดิมในหน้าตั้งค่าเพื่อเปิดไฟล์');
        }
        try {
            $store->client()->streamTo((string)$row['drive_id'], fn() => self::emitHeaders($headers, $row['file_size'] !== null ? (int)$row['file_size'] : null));
        } catch (DriveError $e) {
            $store->noteFailure($e);
            $status = match ($e->kind) {
                DriveError::NOT_FOUND => 410,   // ถูกลบจากฝั่ง Drive แล้ว
                DriveError::AUTH      => 409,   // เจ้าของต้องเชื่อมใหม่ก่อน
                default               => 502,   // Google ตอบผิดปกติ ลองใหม่ได้
            };
            Response::abort($status, match ($e->kind) {
                DriveError::NOT_FOUND => 'ไฟล์นี้ถูกลบจาก Google Drive แล้ว',
                DriveError::AUTH      => 'การเชื่อม Google Drive หลุด เจ้าของไฟล์ต้องเชื่อมใหม่ก่อนจึงจะเปิดไฟล์นี้ได้',
                default               => 'เปิดไฟล์จาก Google Drive ไม่ได้ตอนนี้ ลองอีกครั้ง',
            });
        }
        exit;
    }

    /** ผู้ใช้กดยกเลิกการเชื่อมเอง (ไฟล์เดิมยังอยู่ใน Drive) ต่างจากสิทธิ์หลุด ข้อความที่บอกผู้ใช้จึงต่างกัน */
    public function isDisconnected(): bool
    {
        return ($this->link['state'] ?? null) === 'disconnected';
    }

    /**
     * สิทธิ์หลุดกลางทาง: บันทึก revoked ให้เมนูไฟล์และหน้าตั้งค่าบอกให้เชื่อมใหม่ (EDGE-11)
     * เฉพาะการเชื่อมที่ยังใช้ได้อยู่ ผู้ใช้ที่ยกเลิกการเชื่อมเองคงเป็น disconnected
     */
    public function noteFailure(DriveError $error): void
    {
        if ($error->kind === DriveError::AUTH && $this->link !== null && in_array($this->link['state'], DriveLink::USABLE, true)) {
            DriveLink::setState($this->userId, DriveLink::USABLE, 'revoked', 'Google refused the saved access');
            $this->link['state'] = 'revoked';
        }
    }

    /** ล้าง output ที่ค้าง แล้วส่ง header ของไฟล์ พร้อม Content-Length เมื่อรู้ขนาด */
    private static function emitHeaders(array $headers, ?int $length): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        foreach ($headers as $header) header($header);
        if ($length !== null) header('Content-Length: ' . $length);
    }

    /** ".pdf" จากชื่อไฟล์ หรือ "" ถ้าไม่มีนามสกุล ไฟล์บนดิสก์ใช้ชื่อสุ่ม + นามสกุลเดิม */
    private static function extensionOf(string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return $ext !== '' ? '.' . $ext : '';
    }
}
