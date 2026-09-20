<?php
// =====================================================
// core/RateLimiter.php - Small file-backed limiter for auth endpoints
//
// One file per key, holding the window start and the attempt count. Files are
// swept occasionally: every failed login against a new identifier or IP writes
// one, and nothing used to remove them, so the directory grew without limit.
// =====================================================

class RateLimiter
{
    /** A state file older than this can no longer belong to a live window. */
    private const STALE_AFTER = 86400;

    /** Roughly one sweep per this many calls, so the cost is amortised. */
    private const SWEEP_ODDS = 200;

    public static function hit(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $dir = ROOT . '/storage/ratelimit';
        if (!is_dir($dir)) @mkdir($dir, 0700, true);

        self::maybeSweep($dir);

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

    /**
     * Occasionally deletes state files that no live window can still be using.
     *
     * Sweeping on every call would stat the whole directory on every login
     * attempt; doing it on roughly one call in SWEEP_ODDS keeps the directory
     * bounded for a cost nobody notices.
     */
    private static function maybeSweep(string $dir): void
    {
        if (random_int(1, self::SWEEP_ODDS) !== 1) return;

        $cutoff = time() - self::STALE_AFTER;
        $files  = @glob($dir . '/*.json') ?: [];

        foreach ($files as $file) {
            $modified = @filemtime($file);
            if ($modified !== false && $modified < $cutoff) @unlink($file);
        }
    }
}
