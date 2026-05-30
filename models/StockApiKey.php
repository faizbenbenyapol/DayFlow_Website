<?php
// =====================================================
// models/StockApiKey.php — Encrypted per-user stock-price provider keys
// =====================================================

class StockApiKey
{
    const PROVIDERS = ['finnhub', 'alphavantage', 'twelvedata'];

    public static function listForUser(int $userId): array
    {
        $stmt = DB::run(
            'SELECT provider, api_key_enc, updated_at FROM user_stock_api_keys WHERE user_id = ?',
            [$userId]
        );
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $plain = appDecrypt($row['api_key_enc']);
            $out[$row['provider']] = [
                'set'        => $plain !== '',
                'masked'     => $plain !== '' ? self::mask($plain) : '',
                'updated_at' => $row['updated_at'],
            ];
        }
        return $out;
    }

    public static function get(int $userId, string $provider): string
    {
        if (!in_array($provider, self::PROVIDERS, true)) return '';
        $stmt = DB::run(
            'SELECT api_key_enc FROM user_stock_api_keys WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );
        $row = $stmt->fetch();
        if (!$row) return '';
        return appDecrypt($row['api_key_enc']);
    }

    public static function getFirstAvailable(int $userId): array
    {
        foreach (self::PROVIDERS as $p) {
            $k = self::get($userId, $p);
            if ($k !== '') return ['provider' => $p, 'key' => $k];
        }
        return [];
    }

    public static function save(int $userId, string $provider, string $plainKey): bool
    {
        if (!in_array($provider, self::PROVIDERS, true)) return false;
        $enc = appEncrypt($plainKey);
        DB::run(
            'INSERT INTO user_stock_api_keys (user_id, provider, api_key_enc) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE api_key_enc = VALUES(api_key_enc)',
            [$userId, $provider, $enc]
        );
        return true;
    }

    public static function delete(int $userId, string $provider): bool
    {
        $stmt = DB::run(
            'DELETE FROM user_stock_api_keys WHERE user_id = ? AND provider = ?',
            [$userId, $provider]
        );
        return $stmt->rowCount() > 0;
    }

    private static function mask(string $key): string
    {
        $len = strlen($key);
        if ($len <= 8) return str_repeat('•', $len);
        return substr($key, 0, 4) . '••••••••' . substr($key, -4);
    }
}
