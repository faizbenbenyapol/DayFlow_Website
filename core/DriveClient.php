<?php
// =====================================================
// core/DriveClient.php — คุยกับ Google Drive ด้วย cURL (repo ไม่มี Composer)
//
// รายละเอียดของ protocol ของ Google อยู่ที่ไฟล์นี้ที่เดียว: URL, OAuth, resumable upload,
// รูปแบบ error ส่วนอื่นของแอป (FileStore, DriveMigration, DriveController) เห็นแค่ id ของไฟล์
// เป็นสตริง และ DriveError ที่มีชนิดไม่กี่แบบ ที่อยู่ของ Google มาจาก config (ชุดทดสอบชี้ไป Google ปลอม)
// =====================================================

/** error ของ Drive ที่ส่วนอื่นต้องแยกแยะ ชนิดตัดสินว่าแอปทำอะไรต่อ ไม่ใช่ข้อความของ Google */
final class DriveError extends RuntimeException
{
    /** สิทธิ์หลุด: ผู้ใช้ยกเลิกสิทธิ์ที่หน้าบัญชี Google หรือ refresh token ใช้ไม่ได้ (EDGE-11) */
    public const AUTH = 'auth';
    /** Drive เต็ม (EDGE-12) */
    public const QUOTA = 'quota';
    /** ไม่มีไฟล์นี้ หรือถูกลบจากฝั่ง Drive แล้ว */
    public const NOT_FOUND = 'not_found';
    public const OTHER = 'other';

    public function __construct(public readonly string $kind, string $message)
    {
        parent::__construct($message);
    }
}

final class DriveClient
{
    /** เห็นและแก้ได้เฉพาะไฟล์ที่ DayFlow สร้าง ไม่ต้องผ่านการตรวจแอปของ Google (Q-1) */
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';
    /** ชิ้นของ resumable upload: Google ให้เป็นพหุคูณของ 256 KB */
    private const UPLOAD_CHUNK = 8 * 1024 * 1024;
    private const FOLDER_MIME = 'application/vnd.google-apps.folder';
    /** ต่ออายุ access token ก่อนหมดจริงเผื่อเวลาเดินทางของคำขอ */
    private const EXPIRY_MARGIN_SECONDS = 60;

    /**
     * @param string        $refreshToken     ใช้ขอ access token ใหม่
     * @param string|null   $accessToken      ที่มีอยู่ (null = ขอใหม่ตอนใช้ครั้งแรก)
     * @param int           $accessExpiresAt  unix time ที่ access token หมดอายุ
     * @param callable(string, int): void $onRefresh  ได้ access token ใหม่แล้วเรียก (token, หมดอายุ unix time) ให้ผู้เรียกบันทึก
     */
    public function __construct(
        private string $refreshToken,
        private ?string $accessToken,
        private int $accessExpiresAt,
        private $onRefresh,
    ) {
    }

    /** เจ้าของระบบใส่ client ID และ secret แล้วหรือยัง ไม่ใส่ = ปิดความสามารถ Drive (REQ-23) */
    public static function isConfigured(): bool
    {
        return GOOGLE_DRIVE_CLIENT_ID !== '' && GOOGLE_DRIVE_CLIENT_SECRET !== '';
    }

    /** ที่อยู่ที่ Google ส่งผู้ใช้กลับมาหลังกดอนุญาต ต้องลงทะเบียนไว้ใน Google Cloud Console ตรงตัว */
    public static function redirectUri(): string
    {
        return APP_URL . '/settings/drive/callback';
    }

    /**
     * หน้าขออนุญาตของ Google @param string $state ค่าสุ่มที่ผูกกับ session กันคนอื่นปลอมคำตอบ (CSRF)
     * access_type=offline + prompt=consent ทำให้ได้ refresh token ทุกครั้ง แม้เคยอนุญาตไว้แล้ว
     */
    public static function authUrl(string $state): string
    {
        return GOOGLE_AUTH_URL . '?' . http_build_query([
            'client_id'     => GOOGLE_DRIVE_CLIENT_ID,
            'redirect_uri'  => self::redirectUri(),
            'response_type' => 'code',
            'scope'         => self::SCOPE,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state,
        ]);
    }

    /**
     * แลก code จากหน้าขออนุญาตเป็น token
     * @return array{refresh_token: string, access_token: string, expires_at: int}
     * @throws DriveError AUTH เมื่อ code ใช้ไม่ได้ หรือ Google ไม่ส่ง refresh token มา
     */
    public static function exchangeCode(string $code): array
    {
        $result = self::post(GOOGLE_TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => GOOGLE_DRIVE_CLIENT_ID,
            'client_secret' => GOOGLE_DRIVE_CLIENT_SECRET,
            'redirect_uri'  => self::redirectUri(),
        ]);
        if (empty($result['refresh_token']) || empty($result['access_token'])) {
            throw new DriveError(DriveError::AUTH, 'Google did not return tokens: ' . ($result['error'] ?? 'unknown'));
        }
        return ['refresh_token' => $result['refresh_token'], 'access_token' => $result['access_token'], 'expires_at' => time() + (int)$result['expires_in']];
    }

    /** ขอให้ Google ยกเลิกสิทธิ์ของ token นี้ ล้มก็ไม่เป็นไร (ผู้ใช้ยกเลิกเองที่หน้าบัญชี Google ได้) */
    public static function revoke(string $token): void
    {
        try {
            self::post(GOOGLE_REVOKE_URL, ['token' => $token]);
        } catch (Throwable) {
            // ยกเลิกฝั่งเราเสร็จแล้ว ฝั่ง Google เป็นแค่การเก็บกวาด
        }
    }

    /** อีเมลของบัญชี Google ที่เชื่อมอยู่ (แสดงในหน้าตั้งค่า REQ-20) */
    public function email(): string
    {
        $about = $this->api('GET', '/drive/v3/about?fields=user(emailAddress)');
        return (string)($about['user']['emailAddress'] ?? '');
    }

    /** สร้างโฟลเดอร์ @param string $parentId 'root' = My Drive @return string id ของโฟลเดอร์ใหม่ */
    public function createFolder(string $name, string $parentId): string
    {
        $created = $this->api('POST', '/drive/v3/files?fields=id', ['name' => $name, 'mimeType' => self::FOLDER_MIME, 'parents' => [$parentId]]);
        return (string)$created['id'];
    }

    /**
     * อัปโหลดไฟล์จากดิสก์แบบ resumable ทีละชิ้น ไม่โหลดทั้งไฟล์เข้าหน่วยความจำ
     * @return array{id: string, size: int} ขนาดที่ Google บันทึกไว้ ผู้เรียกใช้เทียบกับไฟล์ต้นทาง
     * @throws DriveError QUOTA เมื่อ Drive เต็ม, AUTH เมื่อสิทธิ์หลุด
     */
    public function upload(string $localPath, string $name, string $mime, string $parentId): array
    {
        $total = filesize($localPath);
        $start = $this->send('POST', GOOGLE_API_URL . '/upload/drive/v3/files?uploadType=resumable&fields=id,size',
            json_encode(['name' => $name, 'mimeType' => $mime, 'parents' => [$parentId]]),
            ['Content-Type: application/json; charset=UTF-8', 'X-Upload-Content-Type: ' . $mime, 'X-Upload-Content-Length: ' . $total]);
        $session = $start['headers']['location'] ?? '';
        if ($session === '') throw new DriveError(DriveError::OTHER, 'Google did not open an upload session');

        $handle = fopen($localPath, 'rb');
        try {
            $offset = 0;
            do {
                $bytes = $total > 0 ? (string)fread($handle, self::UPLOAD_CHUNK) : '';
                $end = $offset + strlen($bytes) - 1;
                $headers = $total > 0 ? ["Content-Range: bytes {$offset}-{$end}/{$total}"] : [];
                $reply = $this->send('PUT', $session, $bytes, $headers, [308]);
                $offset += strlen($bytes);
            } while ($reply['status'] === 308);
        } finally {
            fclose($handle);
        }
        $file = json_decode($reply['body'], true) ?: [];
        // ไม่มี id = ไม่รู้ว่าไฟล์อยู่ไหนใน Drive ถ้าปล่อยผ่าน การย้ายจะลบไฟล์บนเซิร์ฟเวอร์ทั้งที่เปิดจาก Drive ไม่ได้
        if (empty($file['id'])) throw new DriveError(DriveError::OTHER, 'Google Drive finished the upload without a file id');
        return ['id' => (string)$file['id'], 'size' => (int)($file['size'] ?? 0)];
    }

    /** เปลี่ยนชื่อไฟล์หรือโฟลเดอร์ใน Drive */
    public function rename(string $id, string $name): void
    {
        $this->api('PATCH', '/drive/v3/files/' . rawurlencode($id) . '?fields=id', ['name' => $name]);
    }

    /** ย้ายไฟล์หรือโฟลเดอร์จากโฟลเดอร์แม่หนึ่งไปอีกโฟลเดอร์ */
    public function move(string $id, string $fromParentId, string $toParentId): void
    {
        $this->api('PATCH', '/drive/v3/files/' . rawurlencode($id) . '?fields=id&addParents=' . rawurlencode($toParentId) . '&removeParents=' . rawurlencode($fromParentId), []);
    }

    /** ย้ายลงถังขยะของ Drive (กู้คืนได้ 30 วันตามนโยบายของ Google) ไม่ลบถาวร (REQ-21) */
    public function trash(string $id): void
    {
        try {
            $this->api('PATCH', '/drive/v3/files/' . rawurlencode($id) . '?fields=id', ['trashed' => true]);
        } catch (DriveError $e) {
            if ($e->kind !== DriveError::NOT_FOUND) throw $e;   // ถูกลบจากฝั่ง Drive ไปแล้ว ผลเท่ากัน
        }
    }

    /**
     * ส่งเนื้อไฟล์จาก Drive ต่อไปที่เบราว์เซอร์ทีละส่วน ไม่เก็บทั้งก้อนไว้ในหน่วยความจำ
     * @param callable(): void $beforeFirstByte เรียกครั้งเดียวก่อนส่งไบต์แรก ให้ผู้เรียกส่ง header ของตัวเอง
     * @throws DriveError ก่อนไบต์แรกเท่านั้น (NOT_FOUND เมื่อไฟล์ถูกลบจากฝั่ง Drive)
     */
    public function streamTo(string $id, callable $beforeFirstByte): void
    {
        for ($attempt = 0; ; $attempt++) {
            $status = $this->streamOnce($id, $beforeFirstByte, $errorBody, $started, $failed);
            // 401 = access token ใช้ไม่ได้ก่อนเวลา ยังไม่ได้ส่งไบต์ใดออกไป ขอใหม่แล้วลองอีกครั้งเดียวเหมือน send()
            if ($status === 401 && $attempt === 0) {
                $this->accessToken = null;
                continue;
            }
            break;
        }
        if ($status === 200 && !$started) $beforeFirstByte();   // ไฟล์ว่าง ไม่มีไบต์ให้ส่ง
        if ($status !== 200 && !$failed) throw self::errorFor($status, $errorBody);
        if ($failed && !$started) throw new DriveError(DriveError::OTHER, 'Could not reach Google Drive');
    }

    /**
     * ดาวน์โหลดหนึ่งรอบ ส่งเนื้อออกเฉพาะเมื่อ Google ตอบ 200 คืน status ของคำตอบ
     * @param-out string $errorBody เนื้อคำตอบเมื่อไม่ใช่ 200
     * @param-out bool $started ส่งไบต์แรกออกไปแล้วหรือยัง
     * @param-out bool $failed ต่อ Google ไม่ได้หรือหลุดกลางทาง
     */
    private function streamOnce(string $id, callable $beforeFirstByte, ?string &$errorBody, ?bool &$started, ?bool &$failed): int
    {
        $status = 0;
        $errorBody = '';
        $started = false;
        $ch = curl_init(GOOGLE_API_URL . '/drive/v3/files/' . rawurlencode($id) . '?alt=media');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $this->accessToken()],
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$status): int {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) $status = (int)$m[1];
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION  => function ($ch, string $chunk) use (&$status, &$errorBody, &$started, $beforeFirstByte): int {
                if ($status !== 200) {
                    $errorBody .= $chunk;
                    return strlen($chunk);
                }
                if (!$started) {
                    $started = true;
                    $beforeFirstByte();
                }
                echo $chunk;
                flush();
                return strlen($chunk);
            },
        ]);
        curl_exec($ch);
        $failed = curl_errno($ch) !== 0;
        curl_close($ch);
        return $status;
    }

    // ---------------------------------------------------------------- ภายใน

    /** access token ที่ยังใช้ได้ ขอใหม่ด้วย refresh token เมื่อใกล้หมด แล้วแจ้งผู้เรียกให้บันทึก */
    private function accessToken(): string
    {
        if ($this->accessToken !== null && $this->accessExpiresAt - self::EXPIRY_MARGIN_SECONDS > time()) return $this->accessToken;

        $result = self::post(GOOGLE_TOKEN_URL, [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->refreshToken,
            'client_id'     => GOOGLE_DRIVE_CLIENT_ID,
            'client_secret' => GOOGLE_DRIVE_CLIENT_SECRET,
        ]);
        if (empty($result['access_token'])) {
            // invalid_grant = ผู้ใช้ยกเลิกสิทธิ์แล้ว หรือ refresh token หมดอายุ ต้องเชื่อมใหม่ (EDGE-11)
            throw new DriveError(DriveError::AUTH, 'Google refused the refresh token: ' . ($result['error'] ?? 'unknown'));
        }
        $this->accessToken = (string)$result['access_token'];
        $this->accessExpiresAt = time() + (int)$result['expires_in'];
        ($this->onRefresh)($this->accessToken, $this->accessExpiresAt);
        return $this->accessToken;
    }

    /** เรียก Drive API แบบ JSON @return array คำตอบที่ถอด JSON แล้ว @throws DriveError */
    private function api(string $method, string $path, ?array $json = null): array
    {
        $reply = $this->send($method, GOOGLE_API_URL . $path, $json === null ? null : json_encode($json, JSON_UNESCAPED_UNICODE),
            $json === null ? [] : ['Content-Type: application/json; charset=UTF-8']);
        return json_decode($reply['body'], true) ?: [];
    }

    /**
     * ส่งคำขอพร้อม access token ถ้าได้ 401 (token หมดก่อนเวลา) ขอ token ใหม่แล้วลองอีกครั้งเดียว
     * @param list<int> $alsoOk status อื่นที่ไม่ใช่ error เช่น 308 ของ resumable upload
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function send(string $method, string $url, ?string $body, array $headers, array $alsoOk = []): array
    {
        for ($attempt = 0; ; $attempt++) {
            $reply = self::http($method, $url, $body, array_merge($headers, ['Authorization: Bearer ' . $this->accessToken()]));
            if ($reply['status'] === 401 && $attempt === 0) {
                $this->accessToken = null;
                continue;
            }
            if (($reply['status'] >= 200 && $reply['status'] < 300) || in_array($reply['status'], $alsoOk, true)) return $reply;
            throw self::errorFor($reply['status'], $reply['body']);
        }
    }

    /** แปลง error ของ Google เป็นชนิดที่แอปใช้ตัดสินใจ */
    private static function errorFor(int $status, string $body): DriveError
    {
        $reasons = array_column(json_decode($body, true)['error']['errors'] ?? [], 'reason');
        if ($status === 401) return new DriveError(DriveError::AUTH, 'Google Drive access was revoked');
        if (in_array('storageQuotaExceeded', $reasons, true)) return new DriveError(DriveError::QUOTA, 'Google Drive is full');
        if ($status === 404) return new DriveError(DriveError::NOT_FOUND, 'File not found in Google Drive');
        return new DriveError(DriveError::OTHER, "Google Drive answered {$status}");
    }

    /** POST แบบฟอร์มไปที่ endpoint ของ OAuth (token, revoke) @return array คำตอบที่ถอด JSON แล้ว */
    private static function post(string $url, array $fields): array
    {
        $reply = self::http('POST', $url, http_build_query($fields), ['Content-Type: application/x-www-form-urlencoded']);
        return json_decode($reply['body'], true) ?: [];
    }

    /**
     * คำขอ HTTP หนึ่งครั้ง header ของคำตอบเก็บเป็นตัวพิมพ์เล็ก
     * @return array{status: int, headers: array<string, string>, body: string}
     * @throws DriveError OTHER เมื่อเชื่อมต่อ Google ไม่ได้
     */
    private static function http(string $method, string $url, ?string $body, array $headers): array
    {
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        $raw = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $failed = $raw === false;
        curl_close($ch);
        if ($failed) throw new DriveError(DriveError::OTHER, 'Could not reach Google');
        return ['status' => $status, 'headers' => $responseHeaders, 'body' => (string)$raw];
    }
}
