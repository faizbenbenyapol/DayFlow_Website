<?php
// core/AiEndpoint.php — ตรวจ URL ของ AI ที่ผู้ใช้ใส่เองก่อนยิงออก และปัก IP ที่ตรวจแล้ว
// กัน SSRF: URL ต้องชี้ที่อยู่สาธารณะ เว้นแต่เจ้าของระบบเปิด AI_ALLOW_PRIVATE_URLS
// (เช่น Ollama บนเซิร์ฟเวอร์เดียวกัน) ผลลัพธ์ใช้เป็น CURLOPT_RESOLVE เพื่อไม่ให้ cURL
// resolve ชื่อนั้นใหม่เองแล้วอาจได้ IP ภายใน

final class AiEndpoint
{
    /**
     * ตรวจ $url แล้วคืนค่า "host:port:ip" สำหรับ CURLOPT_RESOLVE
     *
     * @param bool $allowPrivate true = ยอมให้ชี้ที่อยู่ภายใน (เจ้าของระบบเปิดเอง)
     * @throws RuntimeException เมื่อ URL ผิดรูป หาที่อยู่ไม่เจอ หรือเป็นที่อยู่ภายใน
     */
    public static function pin(string $url, bool $allowPrivate): string
    {
        $parts = parse_url($url);
        if ($parts === false) throw new RuntimeException('URL ไม่ถูกต้อง');

        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'http' && $scheme !== 'https') {
            throw new RuntimeException('URL ต้องขึ้นต้นด้วย http:// หรือ https://');
        }
        $host = trim((string)($parts['host'] ?? ''), '[]');
        if ($host === '') throw new RuntimeException('URL ไม่ถูกต้อง');
        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('URL ต้องไม่มีชื่อผู้ใช้หรือรหัสผ่าน');
        }

        $port = (int)($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ips = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) throw new RuntimeException('หาที่อยู่ของ URL นี้ไม่เจอ');

        if (!$allowPrivate) {
            // GLOBAL_RANGE ตัด 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, 0/8,
            // ::1, fc00::/7 และ IPv4-mapped IPv6 (::ffff:127.0.0.1) ออกทั้งหมด
            foreach ($ips as $ip) {
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
                    throw new RuntimeException('URL นี้ชี้ไปที่อยู่ภายในของเซิร์ฟเวอร์ ใช้ไม่ได้');
                }
            }
        }

        $ip = $ips[0];
        return (str_contains($host, ':') ? "[{$host}]" : $host)
            . ':' . $port . ':'
            . (str_contains($ip, ':') ? "[{$ip}]" : $ip);
    }

    /** เจ้าของระบบยอมให้ URL ชี้ที่อยู่ภายในหรือไม่ (ค่าใน .env, ค่าเริ่มต้นคือไม่ยอม) */
    public static function allowPrivate(): bool
    {
        return envValue('AI_ALLOW_PRIVATE_URLS') === 'true';
    }
}
