<?php
// =====================================================
// controllers/FileTransferController.php — ส่งไฟล์ด้วยรหัส 6 หลัก
//
// ผู้ส่ง (ต้องเข้าสู่ระบบ): start → chunk ทีละ 8 MB → finish แล้วได้รหัสและ QR
// ผู้รับ (ไม่ต้องมีบัญชี): ใส่รหัสที่ /transfer/receive หรือเปิดลิงก์/QR
// ไปหน้า /transfer/download/{token} ที่แสดงข้อความและไฟล์ ดาวน์โหลดทีละไฟล์หรือ ZIP
// =====================================================

require_once ROOT . '/models/FileTransfer.php';

class FileTransferController
{
    /** หน้าส่งไฟล์ในแอป: ส่ง รับ และประวัติ */
    public function index(): void
    {
        FileTransfer::cleanup();

        $pageTitle  = 'ส่งไฟล์';
        $pageStyle  = 'transfer';
        $pageScript = 'transfer';
        $loadQrLib  = true;

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/transfer/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    /**
     * เริ่มการส่ง (REQ-16, 18, 19): ตรวจขนาดรวม พื้นที่ดิสก์ ข้อความ และเวลาหมดอายุก่อนรับไบต์แรก
     * ส่งแค่ข้อความได้รหัสทันที มีไฟล์ได้ id กับขนาดชิ้นไปอัปโหลดต่อ
     */
    public function apiStart(): void
    {
        $files = FileTransfer::cleanFiles(Request::list('files') ?? []);
        if ($files === null) Response::json(['error' => 'รายการไฟล์ไม่ถูกต้อง'], 422);
        $message = str_replace("\r\n", "\n", (string)Request::input('message', ''));
        $expiry = (int)Request::input('expiry_minutes', FileTransfer::EXPIRY_CHOICES[0]);

        $problem = FileTransfer::startError($files, $message, $expiry);
        if ($problem !== null) Response::json(['error' => $problem[1]], $problem[0]);

        $transfer = FileTransfer::start(Auth::userId(), $files, $message, $expiry);
        if ($transfer['status'] === 'ready') {
            Response::json(self::readyPayload($transfer), 201);
        }
        Response::json(['id' => (int)$transfer['id'], 'chunk_bytes' => FileTransfer::CHUNK_BYTES], 201);
    }

    /**
     * รับชิ้นหนึ่งของไฟล์ (ข้อมูลดิบใน body) ที่ ?file=ลำดับไฟล์&offset=ไบต์เริ่ม (REQ-17)
     * ส่งชิ้นเดิมซ้ำได้ การส่งของคนอื่นหรือที่เสร็จแล้วตอบ 404 เหมือนไม่มี
     */
    public function apiChunk(string $id): void
    {
        $transfer = FileTransfer::findUploading((int)$id, Auth::userId());
        if (!$transfer) Response::json(['error' => 'ไม่พบการส่งนี้ หรือหมดเวลาอัปโหลดแล้ว'], 404);

        $index = Request::query('file', '');
        $offset = Request::query('offset', '');
        if (!ctype_digit((string)$index) || !ctype_digit((string)$offset)) Response::json(['error' => 'ตำแหน่งของชิ้นไม่ถูกต้อง'], 422);

        $problem = FileTransfer::writeChunk($transfer, (int)$index, (int)$offset, (string)file_get_contents('php://input'));
        if ($problem !== null) Response::json(['error' => $problem], 422);
        Response::json(['ok' => true]);
    }

    /** ปิดการอัปโหลดเมื่อทุกไฟล์ครบ แล้วคืนรหัส ลิงก์ และเวลาที่เหลือ (REQ-19 เริ่มนับตอนนี้) */
    public function apiFinish(string $id): void
    {
        $transfer = FileTransfer::findUploading((int)$id, Auth::userId());
        if (!$transfer) Response::json(['error' => 'ไม่พบการส่งนี้ หรือหมดเวลาอัปโหลดแล้ว'], 404);

        $problem = FileTransfer::finish($transfer);
        if ($problem !== null) Response::json(['error' => $problem], 422);
        Response::json(self::readyPayload(FileTransfer::getById((int)$transfer['id'])));
    }

    /**
     * สิ่งที่ผู้ส่งได้หลังส่งเสร็จ expires_in คือวินาทีที่เหลือ หน้าเว็บนับถอยหลังจากค่านี้
     * ไม่ใช้ expires_at เพราะเป็นเวลาของเซิร์ฟเวอร์ ซึ่งเบราว์เซอร์ในเขตเวลาอื่นจะเพี้ยนไปหลายชั่วโมง
     */
    private static function readyPayload(array $transfer): array
    {
        $files = json_decode($transfer['files_json'], true) ?: [];
        return [
            'ok'           => true,
            'id'           => (int)$transfer['id'],
            'code'         => $transfer['code'],
            'token'        => $transfer['token'],
            'download_url' => APP_URL . '/transfer/download/' . $transfer['token'],
            'expires_in'   => max(0, strtotime($transfer['expires_at']) - time()),
            'files'        => array_map(fn(array $f): array => ['name' => $f['name'], 'size' => (int)$f['size']], $files),
            'total_size'   => (int)$transfer['total_size'],
            'message'      => (string)($transfer['message'] ?? ''),
        ];
    }

    /** ประวัติการส่งของผู้ใช้ */
    public function apiList(): void
    {
        $transfers = FileTransfer::listByUser(Auth::userId());
        foreach ($transfers as &$t) {
            $t['is_expired'] = strtotime($t['expires_at']) <= time();
            $t['download_url'] = APP_URL . '/transfer/download/' . $t['token'];
        }
        Response::json(['transfers' => $transfers]);
    }

    /** ยกเลิกการส่งและลบไฟล์ทันที ใช้ทั้งปุ่มยกเลิกและตอนอัปโหลดล้ม */
    public function apiDelete(string $id): void
    {
        if (!FileTransfer::delete((int)$id, Auth::userId())) Response::json(['error' => 'ไม่พบรายการ'], 404);
        Response::json(['ok' => true]);
    }

    /** หารหัส 6 หลัก (ไม่ต้องมีบัญชี) แล้วบอกข้อความ ไฟล์ และลิงก์หน้ารับ */
    public function apiReceive(): void
    {
        [$transfer, $status, $error] = self::findByCode((string)Request::input('code', ''));
        if ($transfer === null) Response::json(['error' => $error], $status);
        $files = json_decode($transfer['files_json'], true) ?: [];
        Response::json([
            'ok'           => true,
            'download_url' => APP_URL . '/transfer/download/' . $transfer['token'],
            'message'      => (string)($transfer['message'] ?? ''),
            'files'        => array_map(fn(array $f): array => ['name' => $f['name'], 'size' => (int)$f['size']], $files),
            'files_count'  => count($files),
            'total_size'   => (int)$transfer['total_size'],
        ]);
    }

    /**
     * หาการส่งจากรหัส 6 หลัก พร้อมกันการเดา: หนึ่งล้านรหัสที่ไม่ต้องมีบัญชี ถ้าไม่จำกัดจะไล่ครบได้ในไม่กี่นาที
     * นับครั้งที่สำเร็จด้วย ไม่งั้นใช้รหัสของตัวเองล้างตัวนับระหว่างเดาได้ ใช้ทั้ง API และหน้ารับไฟล์
     * @return array{0: array|null, 1: int, 2: string} [การส่ง หรือ null, HTTP status, ข้อความ error]
     */
    private static function findByCode(string $code): array
    {
        if (!RateLimiter::hit('transfer-receive:' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 30, 600)) {
            return [null, 429, 'ลองใส่รหัสมากเกินไป กรุณารอประมาณ 10 นาที'];
        }
        if (!preg_match('/^\d{6}$/', trim($code))) return [null, 422, 'กรุณาใส่รหัส 6 หลัก'];
        $transfer = FileTransfer::getByCode(trim($code));
        if (!FileTransfer::isValid($transfer)) return [null, 404, 'รหัสไม่ถูกต้อง หรือหมดอายุแล้ว ตรวจรหัสกับผู้ส่งอีกครั้ง'];
        return [$transfer, 200, ''];
    }

    /**
     * หน้ารับไฟล์สำหรับคนที่ไม่มีบัญชี: ฟอร์มใส่รหัส ส่งแบบ GET ?code= เจอแล้วพาไปหน้าผลลัพธ์
     * เป็นฟอร์มธรรมดา ใช้ได้โดยไม่ต้องมี JS
     */
    public function receivePage(): void
    {
        $code = trim((string)Request::query('code', ''));
        $error = '';
        if ($code !== '') {
            [$found, , $error] = self::findByCode($code);
            if ($found !== null) Response::redirect('/transfer/download/' . $found['token']);
        }
        self::renderReceive(null, $code, $error);
    }

    /** หน้าผลลัพธ์ของลิงก์หรือ QR: ข้อความ ลิงก์ที่กดได้ และไฟล์ทีละไฟล์ (REQ-18) หมดอายุตอบ 410 */
    public function download(string $token): void
    {
        $transfer = FileTransfer::getByToken($token);
        if (FileTransfer::isValid($transfer)) {
            self::renderReceive($transfer, '', '');
        }
        http_response_code(410);
        self::renderReceive(null, '', 'ลิงก์นี้หมดอายุหรือถูกยกเลิกแล้ว ขอรหัสใหม่จากผู้ส่ง');
    }

    /** วาดหน้ารับไฟล์ (views/transfer/receive.php) แล้วจบคำขอ $transfer = null คือแสดงฟอร์มใส่รหัส */
    private static function renderReceive(?array $transfer, string $code, string $error): never
    {
        $pageTitle = 'รับไฟล์';
        require ROOT . '/views/transfer/receive.php';
        exit;
    }

    /**
     * ดาวน์โหลดไฟล์เดี่ยว (/{token}/{ลำดับ}) หรือ ZIP ทั้งหมด (/{token}/all) เมื่อรวมไม่เกิน 2 GB
     * ส่งเป็น application/octet-stream แบบ attachment เสมอ ไฟล์ HTML หรือ SVG
     * จึงไม่ถูกเปิดเป็นหน้าเว็บในโดเมนของ DayFlow (EDGE-10)
     */
    public function downloadPart(string $token, string $part): void
    {
        $transfer = FileTransfer::getByToken($token);
        if (!FileTransfer::isValid($transfer)) Response::abort(410, 'ลิงก์นี้หมดอายุแล้ว');
        $files = json_decode($transfer['files_json'], true) ?: [];

        if ($part === 'all') {
            if (!FileTransfer::canZip($files)) Response::abort(404, 'ไฟล์ชุดนี้ใหญ่เกินกว่าจะรวมเป็น ZIP ดาวน์โหลดทีละไฟล์แทน');
            FileTransfer::incrementDownload((int)$transfer['id']);
            self::sendZip($files, 'transfer_' . $transfer['code'] . '.zip');
        }
        if (!ctype_digit($part) || !isset($files[(int)$part])) Response::abort(404, 'ไม่พบไฟล์นี้ในการส่ง');

        $file = $files[(int)$part];
        FileTransfer::incrementDownload((int)$transfer['id']);
        self::sendFile(UPLOAD_DIR . $file['path'], (string)$file['name']);
    }

    /** ส่งไฟล์จากดิสก์ทีละส่วนโดยไม่โหลดทั้งก้อนเข้าหน่วยความจำ (ไฟล์ใหญ่ได้ถึง 10 GB) แล้วจบคำขอ */
    private static function sendFile(string $path, string $name): never
    {
        if (!is_file($path)) Response::abort(404, 'ไม่พบไฟล์บนเซิร์ฟเวอร์');
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: ' . contentDisposition($name));
        header('Content-Length: ' . filesize($path));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        readfile($path);
        exit;
    }

    /** รวมไฟล์เป็น ZIP แบบไม่บีบอัด (เร็ว และไฟล์ส่วนใหญ่บีบอัดอยู่แล้ว) ชื่อซ้ำได้ (2) ต่อท้าย */
    private static function sendZip(array $files, string $zipName): never
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'ft_dl_') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) Response::abort(500, 'สร้างไฟล์ ZIP ไม่ได้');

        $used = [];
        foreach ($files as $f) {
            $name = (string)$f['name'];
            $stem = pathinfo($name, PATHINFO_FILENAME);
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            for ($n = 2; isset($used[$name]); $n++) $name = $stem . " ({$n})" . ($ext !== '' ? ".{$ext}" : '');
            $used[$name] = true;
            $zip->addFile(UPLOAD_DIR . $f['path'], $name);
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
        $zip->close();
        register_shutdown_function(fn() => @unlink($tmpZip));
        self::sendFile($tmpZip, $zipName);
    }
}
