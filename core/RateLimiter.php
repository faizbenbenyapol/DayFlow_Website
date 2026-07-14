<?php
// =====================================================
// core/RateLimiter.php - Small file-backed limiter for auth endpoints
// =====================================================

class RateLimiter
{
    public static function hit(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $dir = ROOT . '/storage/ratelimit';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);

        $file = $dir . '/' . hash('sha256', $key) . '.json';
        $now = time();
        $state = ['started' => $now, 'attempts' => 0];
        $handle = @fopen($file, 'c+');
        if ($handle === false) return true;
        @flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $decoded = json_decode($raw ?: '', true);
        if (is_array($decoded) && ($now - (int)($decoded['started'] ?? 0)) < $windowSeconds) {
            $state = $decoded;
        }

        $state['attempts'] = (int)$state['attempts'] + 1;
        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);
        return $state['attempts'] <= $maxAttempts;
    }

    public static function clear(string $key): void
    {
        $file = ROOT . '/storage/ratelimit/' . hash('sha256', $key) . '.json';
        if (is_file($file)) @unlink($file);
    }
}
