<?php
// =====================================================
// models/StockQuote.php — live quotes from the configured price provider
// =====================================================

final class StockQuote
{
    /**
     * A live quote: price, previous close and, where the provider has them,
     * valuation ratios (pe, forward_pe, peg, p_fcf, eps).
     *
     * @throws RuntimeException when the provider has no price for the ticker
     */
    public static function fetch(string $provider, string $key, string $ticker): array
    {
        return match ($provider) {
            'finnhub'      => self::finnhub($key, $ticker),
            'alphavantage' => self::alphaVantage($key, $ticker),
            'twelvedata'   => self::twelveData($key, $ticker),
            default        => throw new RuntimeException('Provider ไม่รองรับ'),
        };
    }

    /** Fetches a quote and stores it in the shared price cache. */
    public static function refresh(string $provider, string $key, string $ticker): array
    {
        $q = self::fetch($provider, $key, $ticker);
        StockPriceCache::upsert(
            $ticker,
            $q['price'],
            $q['prev_close'],
            $q['currency'],
            $q['pe'] ?? null,
            $q['forward_pe'] ?? null,
            $q['peg'] ?? null,
            $q['p_fcf'] ?? null,
            $q['eps'] ?? null
        );
        return $q;
    }

    private static function get(string $url): array
    {
        return HttpJson::request($url, null, [], 'GET');
    }

    private static function finnhub(string $key, string $ticker): array
    {
        $r = self::get('https://finnhub.io/api/v1/quote?symbol=' . urlencode($ticker) . '&token=' . urlencode($key));
        $c = isset($r['c']) ? (float)$r['c'] : 0;
        if ($c <= 0) throw new RuntimeException('ไม่พบราคา (ticker อาจผิด หรือ quota หมด)');

        // Ratios are a bonus: a quote without them is still a quote.
        $metric = [];
        try {
            $metric = self::get(
                'https://finnhub.io/api/v1/stock/metric?symbol=' . urlencode($ticker) . '&metric=all&token=' . urlencode($key)
            )['metric'] ?? [];
        } catch (Throwable) {}

        $num = static fn(string $k): ?float => isset($metric[$k]) ? (float)$metric[$k] : null;
        return [
            'price'      => $c,
            'prev_close' => isset($r['pc']) ? (float)$r['pc'] : null,
            'currency'   => null,
            'pe'         => $num('peBasicShare') ?? $num('peTTM'),
            'forward_pe' => $num('peNormalized'),
            'peg'        => $num('pegTTM'),
            'p_fcf'      => $num('pfcfShareTTM'),
            'eps'        => $num('epsBasicShareTTM'),
        ];
    }

    private static function alphaVantage(string $key, string $ticker): array
    {
        $r = self::get('https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=' . urlencode($ticker) . '&apikey=' . urlencode($key));
        $q = $r['Global Quote'] ?? [];
        if (empty($q['05. price'])) throw new RuntimeException('ไม่พบราคา (อาจเกิน rate limit)');

        $ov = [];
        try {
            $ov = self::get('https://www.alphavantage.co/query?function=OVERVIEW&symbol=' . urlencode($ticker) . '&apikey=' . urlencode($key));
        } catch (Throwable) {}

        // Alpha Vantage writes a missing figure as the string "None".
        $num = static fn(string $k): ?float => isset($ov[$k]) && $ov[$k] !== 'None' ? (float)$ov[$k] : null;
        return [
            'price'      => (float)$q['05. price'],
            'prev_close' => isset($q['08. previous close']) ? (float)$q['08. previous close'] : null,
            'currency'   => null,
            'pe'         => $num('PERatio'),
            'forward_pe' => $num('ForwardPE'),
            'peg'        => $num('PEGRatio'),
            'p_fcf'      => null,
            'eps'        => $num('EPS'),
        ];
    }

    private static function twelveData(string $key, string $ticker): array
    {
        $r = self::get('https://api.twelvedata.com/price?symbol=' . urlencode($ticker) . '&apikey=' . urlencode($key));
        if (empty($r['price'])) throw new RuntimeException('ไม่พบราคา');
        return ['price' => (float)$r['price'], 'prev_close' => null, 'currency' => null];
    }
}
