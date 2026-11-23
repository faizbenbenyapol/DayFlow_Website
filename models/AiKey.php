<?php
// =====================================================
// models/AiKey.php — คีย์ AI ของผู้ใช้แต่ละคน (เข้ารหัสด้วย appEncrypt)
// เก็บ URL และโมเดลที่ผู้ใช้เลือกเองไว้ด้วย เพื่อให้ผู้ให้บริการแบบ OpenAI-compatible
// ที่กำหนด URL เองใช้ได้ (migration 036)
// =====================================================

class AiKey
{
    const PROVIDERS = ['openai', 'gemini', 'anthropic', 'replicate', 'kimi', 'openrouter', 'deepseek', 'mistral', 'groq', 'xai', 'together', 'qwen', 'custom'];

    /** รายการคีย์ของผู้ใช้: คีย์เต็มไม่เคยออกจากเซิร์ฟเวอร์ มีแต่ค่าปิดบางส่วน @return array<string, array> */
    public static function listForUser(int $userId): array
    {
        $stmt = DB::run(
            'SELECT provider, api_key_enc, base_url, model, updated_at FROM user_ai_keys WHERE user_id = ?',
            [$userId]
        );
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $plain = appDecrypt($row['api_key_enc']);
            $out[$row['provider']] = [
                'set'        => $plain !== '',
                'masked'     => $plain !== '' ? self::mask($plain) : '',
                'base_url'   => $row['base_url'],
                'model'      => $row['model'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return $out;
    }

    /** คีย์ถอดรหัสของ $provider หรือ '' เมื่อไม่มี @return string */
    public static function get(int $userId, string $provider): string
    {
        if (!in_array($provider, self::PROVIDERS, true)) return '';
        $stmt = DB::run(
            'SELECT api_key_enc FROM user_ai_keys WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );
        $row = $stmt->fetch();
        if (!$row) return '';
        return appDecrypt($row['api_key_enc']);
    }

    /**
     * ค่าที่ต้องใช้เรียกผู้ให้บริการ: provider, key, base_url, model
     *
     * @return array{provider: string, key: string, base_url: ?string, model: ?string}|null null เมื่อไม่มีคีย์ที่ใช้ได้
     */
    public static function config(int $userId, string $provider): ?array
    {
        $stmt = DB::run(
            'SELECT api_key_enc, base_url, model FROM user_ai_keys WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );
        $row = $stmt->fetch();
        if (!$row) return null;
        $key = appDecrypt($row['api_key_enc']);
        if ($key === '') return null;
        return [
            'provider' => $provider,
            'key'      => $key,
            'base_url' => $row['base_url'] !== null && $row['base_url'] !== '' ? (string)$row['base_url'] : null,
            'model'    => $row['model'] !== null && $row['model'] !== '' ? (string)$row['model'] : null,
        ];
    }

    /**
     * บันทึกหรือทับคีย์ $provider พร้อม URL/โมเดล (null = ไม่ใช้/ค่าเริ่มต้น)
     *
     * @return bool false เมื่อผู้ให้บริการไม่รู้จัก
     */
    public static function save(int $userId, string $provider, string $plainKey, ?string $baseUrl = null, ?string $model = null): bool
    {
        if (!in_array($provider, self::PROVIDERS, true)) return false;
        $enc = appEncrypt($plainKey);
        DB::run(
            'INSERT INTO user_ai_keys (user_id, provider, api_key_enc, base_url, model) VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE api_key_enc = VALUES(api_key_enc), base_url = VALUES(base_url), model = VALUES(model)',
            [$userId, $provider, $enc, $baseUrl, $model]
        );
        return true;
    }

    /** ลบคีย์ของผู้ใช้ @return bool มีแถวถูกลบจริงหรือไม่ */
    public static function delete(int $userId, string $provider): bool
    {
        $stmt = DB::run(
            'DELETE FROM user_ai_keys WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );
        return $stmt->rowCount() > 0;
    }

    /** ปิดคีย์ให้เหลือ 4 ตัวแรก/4 ตัวท้าย @return string */
    private static function mask(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) return str_repeat('•', $len);
        return substr($key, 0, 4) . '••••••••' . substr($key, -4);
    }
}
