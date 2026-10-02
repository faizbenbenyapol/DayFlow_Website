<?php
// =====================================================
// models/StockPriceCache.php — Shared in-DB price cache
// =====================================================

class StockPriceCache
{
    public static function getMany(array $tickers): array
    {
        if (!$tickers) return [];
        $placeholders = implode(',', array_fill(0, count($tickers), '?'));
        $stmt = DB::run(
            "SELECT ticker, price, prev_close, currency, fetched_at, pe_ratio, forward_pe, peg_ratio, p_fcf_ratio, eps
             FROM stock_price_cache WHERE ticker IN ($placeholders)",
            $tickers
        );
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[$row['ticker']] = $row;
        }
        return $out;
    }

    public static function get(string $ticker): ?array
    {
        $stmt = DB::run(
            'SELECT ticker, price, prev_close, currency, fetched_at, pe_ratio, forward_pe, peg_ratio, p_fcf_ratio, eps
             FROM stock_price_cache WHERE ticker = ?',
            [$ticker]
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function upsert(string $ticker, float $price, ?float $prevClose, ?string $currency, ?float $pe = null, ?float $forwardPe = null, ?float $peg = null, ?float $pFcf = null, ?float $eps = null): void
    {
        DB::run(
            'INSERT INTO stock_price_cache (ticker, price, prev_close, currency, pe_ratio, forward_pe, peg_ratio, p_fcf_ratio, eps)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                price = VALUES(price),
                prev_close = VALUES(prev_close),
                currency = VALUES(currency),
                pe_ratio = VALUES(pe_ratio),
                forward_pe = VALUES(forward_pe),
                peg_ratio = VALUES(peg_ratio),
                p_fcf_ratio = VALUES(p_fcf_ratio),
                eps = VALUES(eps),
                fetched_at = CURRENT_TIMESTAMP',
            [$ticker, $price, $prevClose, $currency, self::ratio($pe), self::ratio($forwardPe), self::ratio($peg), self::ratio($pFcf), self::ratio($eps)]
        );
    }

    /** A ratio the provider did not give stays unknown (NULL); it is never made up. */
    private static function ratio(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }
}
