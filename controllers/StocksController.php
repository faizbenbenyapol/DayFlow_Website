<?php
// =====================================================
// controllers/StocksController.php
// =====================================================

require_once ROOT . '/models/Stock.php';
require_once ROOT . '/models/StockApiKey.php';
require_once ROOT . '/models/StockPriceCache.php';
require_once ROOT . '/models/AiKey.php';

class StocksController
{
    const CACHE_COOLDOWN_SECONDS = 300; // 5 min

    public function index(): void
    {
        $pageTitle   = 'หุ้น';
        $pageStyle   = 'stocks';
        $pageScript  = 'stocks';
        $loadChartJs = true;

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/stocks/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    // ============================================================
    // TRANSACTIONS
    // ============================================================

    public function apiList(): void
    {
        $userId = Auth::userId();
        $filters = [
            'ticker' => Request::query('ticker', ''),
            'market' => Request::query('market', ''),
        ];
        Response::json(['transactions' => Stock::listForUser($userId, $filters)]);
    }

    public function apiCreate(): void
    {
        $userId = Auth::userId();
        $data   = $this->validateData();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        $id  = Stock::create($userId, $data);
        $txn = Stock::getById($id, $userId);
        Response::json(['ok' => true, 'transaction' => $txn], 201);
    }

    public function apiUpdate(string $id): void
    {
        $userId = Auth::userId();
        $txnId  = (int)$id;
        $txn    = Stock::getById($txnId, $userId);
        if (!$txn) Response::json(['error' => 'ไม่พบรายการ'], 404);

        $data = $this->validateData();
        if (isset($data['error'])) Response::json(['error' => $data['error']], 422);

        Stock::update($txnId, $userId, $data);
        Response::json(['ok' => true]);
    }

    public function apiDelete(string $id): void
    {
        $userId = Auth::userId();
        if (!Stock::delete((int)$id, $userId)) {
            Response::json(['error' => 'ไม่พบรายการ'], 404);
        }
        Response::json(['ok' => true]);
    }

    // ============================================================
    // PORTFOLIO & CHART
    // ============================================================

    public function apiSummary(): void
    {
        $userId = Auth::userId();
        Response::json(Stock::portfolioForUser($userId));
    }

    public function apiChart(): void
    {
        $userId = Auth::userId();
        $year   = (int)Request::query('year', (int)date('Y'));
        Response::json([
            'year' => $year,
            'series' => Stock::monthlyValueSeries($userId, $year),
        ]);
    }

    // ============================================================
    // WATCHLIST
    // ============================================================

    public function apiWatchlists(): void
    {
        $userId = Auth::userId();
        Response::json(['watchlists' => Stock::getWatchlistsForUser($userId)]);
    }

    public function apiWatchlistToggle(): void
    {
        $userId = Auth::userId();
        $ticker = strtoupper(trim((string)Request::rawInput('ticker', '')));
        $market = Request::rawInput('market', 'US');
        $action = Request::rawInput('action', 'add'); // 'add' or 'remove'

        if ($ticker === '' || !preg_match('/^[A-Z0-9.\-]{1,20}$/', $ticker)) {
            Response::json(['error' => 'Ticker ไม่ถูกต้อง'], 422);
        }

        if ($action === 'add') {
            Stock::addWatchlist($userId, $ticker, $market);
        } else {
            Stock::removeWatchlist($userId, $ticker);
        }

        Response::json(['ok' => true]);
    }

    // ============================================================
    // PRICE REFRESH
    // ============================================================

    public function apiRefresh(): void
    {
        $userId  = Auth::userId();
        $tickers = Request::rawInput('tickers', []);

        if (!is_array($tickers) || !$tickers) {
            $tickers = [];
            foreach (Stock::portfolioForUser($userId)['holdings'] as $h) {
                $tickers[] = $h['ticker'];
            }
            foreach (Stock::getWatchlistsForUser($userId) as $w) {
                $tickers[] = $w['ticker'];
            }
        }
        $tickers = array_values(array_unique(array_filter(array_map('strtoupper', $tickers))));
        if (!$tickers) Response::json(['ok' => true, 'updated' => [], 'skipped' => [], 'errors' => []]);

        $keyInfo = StockApiKey::getFirstAvailable($userId);
        if (!$keyInfo) {
            Response::json(['error' => 'กรุณาตั้งค่า API หุ้นก่อน (ตั้งค่า → API หุ้น)'], 422);
        }

        $updated = [];
        $skipped = [];
        $errors  = [];
        $now     = time();

        foreach ($tickers as $ticker) {
            if (!preg_match('/^[A-Z0-9.\-]+$/', $ticker)) {
                $errors[$ticker] = 'Ticker ไม่ถูกต้อง';
                continue;
            }
            $cached = StockPriceCache::get($ticker);
            if ($cached && $cached['fetched_at']) {
                $hasMetrics = ($cached['pe_ratio'] !== null || $cached['forward_pe'] !== null || $cached['peg_ratio'] !== null || $cached['p_fcf_ratio'] !== null || $cached['eps'] !== null);
                $age = $now - strtotime($cached['fetched_at']);
                if ($age < self::CACHE_COOLDOWN_SECONDS && $hasMetrics) {
                    $skipped[] = $ticker;
                    continue;
                }
            }
            try {
                $q = $this->fetchQuote($keyInfo['provider'], $keyInfo['key'], $ticker);
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
                $updated[] = $ticker;
            } catch (\Throwable $e) {
                $errors[$ticker] = $e->getMessage();
            }
        }

        Response::json([
            'ok'       => true,
            'provider' => $keyInfo['provider'],
            'updated'  => $updated,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ]);
    }

    // ============================================================
    // API KEYS
    // ============================================================

    public function apiKeysList(): void
    {
        $userId = Auth::userId();
        Response::json([
            'keys'      => StockApiKey::listForUser($userId),
            'providers' => StockApiKey::PROVIDERS,
        ]);
    }

    public function apiKeysSave(): void
    {
        $userId   = Auth::userId();
        $provider = Request::rawInput('provider', '');
        $apiKey   = trim((string)Request::rawInput('api_key', ''));

        if (!in_array($provider, StockApiKey::PROVIDERS, true)) {
            Response::json(['error' => 'ผู้ให้บริการไม่ถูกต้อง'], 422);
        }
        if ($apiKey === '') {
            StockApiKey::delete($userId, $provider);
            Response::json(['ok' => true, 'deleted' => true]);
        }
        if (strlen($apiKey) < 8) {
            Response::json(['error' => 'API key สั้นเกินไป'], 422);
        }
        StockApiKey::save($userId, $provider, $apiKey);
        Response::json(['ok' => true]);
    }

    public function apiKeysTest(): void
    {
        $userId   = Auth::userId();
        $provider = Request::rawInput('provider', '');
        $apiKey   = trim((string)Request::rawInput('api_key', ''));

        if (!in_array($provider, StockApiKey::PROVIDERS, true)) {
            Response::json(['error' => 'ผู้ให้บริการไม่ถูกต้อง'], 422);
        }
        if ($apiKey === '') $apiKey = StockApiKey::get($userId, $provider);
        if ($apiKey === '') Response::json(['error' => 'ยังไม่มี API key'], 422);

        try {
            $q = $this->fetchQuote($provider, $apiKey, 'AAPL');
            Response::json(['ok' => true, 'message' => 'ใช้งานได้ · AAPL = ' . $q['price']]);
        } catch (\Throwable $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function apiKeysDelete(string $provider): void
    {
        $userId = Auth::userId();
        StockApiKey::delete($userId, $provider);
        Response::json(['ok' => true]);
    }

    // ============================================================
    // AI STOCK ANALYSIS
    // ============================================================

    public function apiAnalyze(): void
    {
        $userId = Auth::userId();
        $ticker = strtoupper(trim((string)Request::rawInput('ticker', '')));
        $market = Request::rawInput('market', 'US');

        if ($ticker === '' || !preg_match('/^[A-Z0-9.\-]{1,20}$/', $ticker)) {
            Response::json(['error' => 'กรุณากรอกสัญลักษณ์หุ้นให้ถูกต้อง'], 422);
        }

        // Find all available AI Keys configured by the user
        $availableKeys = [];
        foreach (['gemini', 'openai', 'anthropic', 'kimi', 'openrouter'] as $p) {
            $key = AiKey::get($userId, $p);
            if ($key !== '') {
                $availableKeys[] = [
                    'provider' => $p,
                    'key'      => $key
                ];
            }
        }

        if (empty($availableKeys)) {
            Response::json(['error' => 'กรุณาตั้งค่า API Key สำหรับ AI ก่อนใช้งาน ในส่วน "API สำหรับการวิเคราะห์หุ้นด้วย AI" ในหน้า ตั้งค่า → API หุ้น'], 422);
        }

        // Try to fetch live quote if we have a Stock Price API key
        $keyInfo = StockApiKey::getFirstAvailable($userId);
        if ($keyInfo) {
            try {
                $q = $this->fetchQuote($keyInfo['provider'], $keyInfo['key'], $ticker);
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
            } catch (\Throwable $e) {
                // Silently ignore if price API fails
            }
        }

        // Check if there is cached price
        $cached = StockPriceCache::get($ticker);
        $currentPriceText = '';
        if ($cached) {
            $currentPriceText = 'ราคาตลาดจริงล่าสุดของหุ้นตัวนี้ ณ ปัจจุบันในระบบ: ' . $cached['price'] . ' ' . ($cached['currency'] ?: 'USD') . ' (ราคาอ้างอิงปิดวันก่อนหน้า: ' . ($cached['prev_close'] ?: '—') . ' ' . ($cached['currency'] ?: 'USD') . ')';
        }

        // Build stock analysis prompt
        $prompt = "คุณเป็นนักวิเคราะห์การเงินและผู้เชี่ยวชาญด้านการลงทุนมืออาชีพ ช่วยวิเคราะห์หุ้นสัญลักษณ์ \"{$ticker}\" (ตลาด: {$market}) โดยใช้ราคาตลาดล่าสุดที่ระบบจัดเตรียมให้คุณเป็นหลักดังนี้: {$currentPriceText}
ช่วยประเมินและให้คำแนะนำแบบมืออาชีพในรูปแบบภาษาไทย

ตอบกลับเป็น JSON เท่านั้น (ห้ามมีข้อความอื่นใด ห้ามใส่ markdown code block หรือคำอธิบายเพิ่มเติมภายนอก JSON) โครงสร้าง JSON ที่ต้องการ:
{
  \"ticker\": \"{$ticker}\",
  \"name\": \"ชื่อบริษัทภาษาไทย/อังกฤษและคำอธิบายสั้นเกี่ยวกับธุรกิจ\",
  \"recommendation\": \"BUY\", // หรือ HOLD หรือ WAIT เท่านั้น (ใช้พิมพ์ใหญ่)
  \"recommendation_label\": \"คำแนะนำภาษาไทย เช่น เข้าซื้อเลย, ถือไว้ก่อน, ชะลอการลงทุน/รอก่อน\",
  \"current_price\": \"" . ($cached ? $cached['price'] . ' ' . ($cached['currency'] ?: 'USD') : "ราคาล่าสุดโดยประมาณ หรือตัวเลขจริง ณ ปัจจุบัน") . "\", // *สำคัญมาก*: ต้องใช้ราคาตลาดปัจจุบันที่ระบบจัดเตรียมให้ข้างต้นเป็นหลักในการกรอกช่องนี้
  \"target_price\": \"ราคาเป้าหมายโดยประมาณ เช่น 180 - 190\",
  \"stop_loss\": \"จุดตัดขาดทุนโดยประมาณ เช่น 155\",
  \"support_1\": \"แนวรับที่ 1 เช่น 160.00\",
  \"support_2\": \"แนวรับที่ 2 เช่น 152.50\",
  \"resistance_1\": \"แนวต้านที่ 1 เช่น 175.50\",
  \"resistance_2\": \"แนวต้านที่ 2 เช่น 182.00\",
  \"revenue\": \"รายได้รวมล่าสุดของบริษัท พร้อมเทียบปีต่อปี เช่น 120.5 พันล้าน USD (+8% YoY)\",
  \"net_profit\": \"กำไรสุทธิล่าสุดและอัตรากำไรสุทธิ เช่น 30.2 พันล้าน USD (Margin: 25.1%)\",
  \"eps\": \"กำไรต่อหุ้น (EPS) ล่าสุด เช่น 6.45 USD\",
  \"pe\": \"P/E เช่น 24.5\",
  \"pb\": \"P/B เช่น 3.8\",
  \"roe\": \"อัตราส่วนผลตอบแทนต่อผู้ถือหุ้น (ROE) เช่น 18.5% หรือ —\",
  \"de_ratio\": \"อัตราส่วนหนี้สินต่อทุน (D/E Ratio) เช่น 0.85\",
  \"free_cash_flow\": \"กระแสเงินสดอิสระ (Free Cash Flow) เช่น 25.4 พันล้าน USD\",
  \"dividend_yield\": \"อัตราปันผล เช่น 2.1% หรือ —\",
  \"trend\": \"แนวโน้ม เช่น ขาขึ้นแข็งแกร่ง, ขาลงระยะสั้น, ไซด์เวย์\",
  \"summary\": \"บทสรุปคำแนะนำสั้นๆ 1-2 ประโยค\",
  \"fundamental_analysis\": \"บทวิเคราะห์ปัจจัยพื้นฐาน เช่น งบการเงิน ความสามารถในการทำกำไร ความคุ้มค่าในการลงทุน\",
  \"technical_analysis\": \"บทวิเคราะห์ทางเทคนิค เช่น ทิศทางราคา สัญญาณบ่งชี้ต่างๆ เช่น EMA, RSI และการเบรคแนวรับแนวต้าน\",
  \"opportunities\": [
    \"โอกาสทางธุรกิจหรือตัวเร่งปฏิกิริยาเชิงบวก 1\",
    \"โอกาสทางธุรกิจหรือตัวเร่งปฏิกิริยาเชิงบวก 2\"
  ],
  \"risks\": [
    \"ปัจจัยความเสี่ยงหรือข้อควรระวัง 1\",
    \"ปัจจัยความเสี่ยงหรือข้อควรระวัง 2\"
  ]
}";

        $lastError = '';
        foreach ($availableKeys as $item) {
            $provider = $item['provider'];
            $apiKey   = $item['key'];

            try {
                $raw = $this->callTextLlm($provider, $apiKey, $prompt);
                $parsed = $this->extractJson($raw);
                if ($parsed) {
                    // Success! Return immediately with the working AI result
                    Response::json(['ok' => true, 'result' => $parsed, 'provider' => $provider]);
                    return;
                }
                $lastError = 'AI ' . $provider . ' ส่งข้อมูลมาไม่ถูกต้อง';
            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                // If it fails (like HTTP 429 or 401), catch the error and continue to the next available AI Key!
            }
        }

        // If we reach here, all configured keys failed
        Response::json(['error' => 'เกิดข้อผิดพลาดในการวิเคราะห์ด้วย AI: ' . $lastError], 500);
    }

    private function callTextLlm(string $provider, string $apiKey, string $prompt): string
    {
        switch ($provider) {
            case 'openai':    return $this->callOpenAi($apiKey, $prompt);
            case 'gemini':    return $this->callGemini($apiKey, $prompt);
            case 'anthropic': return $this->callAnthropic($apiKey, $prompt);
            case 'kimi':      return $this->callKimi($apiKey, $prompt);
            case 'openrouter': return $this->callOpenRouter($apiKey, $prompt);
        }
        throw new \RuntimeException('AI Provider ไม่รองรับ: ' . $provider);
    }

    private function callKimi(string $key, string $prompt): string
    {
        $body = [
            'model' => 'moonshot-v1-8k',
            'messages' => [
                ['role' => 'system', 'content' => 'You output only valid JSON matching the requested schema. No markdown, no explanations.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.3,
        ];
        $resp = $this->httpJson('https://api.moonshot.cn/v1/chat/completions', $body, [
            'Authorization: Bearer ' . $key,
        ]);
        return $resp['choices'][0]['message']['content'] ?? '';
    }

    private function callOpenRouter(string $key, string $prompt): string
    {
        $body = [
            'model' => 'google/gemini-2.0-flash-001',
            'messages' => [
                ['role' => 'system', 'content' => 'You output only valid JSON matching the requested schema. No markdown, no explanations.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.8,
        ];
        $resp = $this->httpJson('https://openrouter.ai/api/v1/chat/completions', $body, [
            'Authorization: Bearer ' . $key,
            'HTTP-Referer: http://localhost',
            'X-Title: Stock Analyzer',
        ]);
        return $resp['choices'][0]['message']['content'] ?? '';
    }

    private function callOpenAi(string $key, string $prompt): string
    {
        $body = [
            'model' => 'gpt-4o-mini',
            'messages' => [
                ['role' => 'system', 'content' => 'You output only valid JSON matching the requested schema. No markdown, no explanations.'],
                ['role' => 'user', 'content' => $prompt],
            ],
            'temperature' => 0.8,
            'response_format' => ['type' => 'json_object'],
        ];
        $resp = $this->httpJson('https://api.openai.com/v1/chat/completions', $body, [
            'Authorization: Bearer ' . $key,
        ]);
        return $resp['choices'][0]['message']['content'] ?? '';
    }

    private function callGemini(string $key, string $prompt): string
    {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . urlencode($key);
        $body = [
            'contents' => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => [
                'temperature' => 0.9,
                'responseMimeType' => 'application/json',
            ],
        ];
        $resp = $this->httpJson($url, $body);
        return $resp['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function callAnthropic(string $key, string $prompt): string
    {
        $body = [
            'model' => 'claude-haiku-4-5-20251001',
            'max_tokens' => 2048,
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ];
        $resp = $this->httpJson('https://api.anthropic.com/v1/messages', $body, [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ]);
        return $resp['content'][0]['text'] ?? '';
    }

    private function extractJson(string $raw): ?array
    {
        $s = trim($raw);
        $s = preg_replace('/^```(?:json)?\s*/i', '', $s);
        $s = preg_replace('/\s*```\s*$/', '', $s);
        $data = json_decode($s, true);
        if (is_array($data)) return $data;
        if (preg_match('/\{.*\}/s', $s, $m)) {
            $data = json_decode($m[0], true);
            if (is_array($data)) return $data;
        }
        return null;
    }

    // ============================================================
    // INTERNAL
    // ============================================================

    private function validateData(): array
    {
        $ticker = strtoupper(trim((string)Request::input('ticker', '')));
        $market = Request::input('market', 'US');
        $side   = Request::input('side', '');
        $qty    = (float)Request::input('quantity', 0);
        $price  = (float)Request::input('price', 0);
        $fee    = (float)Request::input('fee', 0);
        $cur    = strtoupper(trim((string)Request::input('currency', 'USD')));
        $date   = Request::input('txn_date', date('Y-m-d'));
        $notes  = Request::input('notes', '');

        if ($ticker === '' || !preg_match('/^[A-Z0-9.\-]{1,20}$/', $ticker)) {
            return ['error' => 'Ticker ไม่ถูกต้อง'];
        }
        if (!in_array($market, ['US', 'SET', 'OTHER'], true)) {
            return ['error' => 'ตลาดไม่ถูกต้อง'];
        }
        if (!in_array($side, ['buy', 'sell'], true)) {
            return ['error' => 'ประเภทธุรกรรมไม่ถูกต้อง'];
        }
        if ($qty <= 0)  return ['error' => 'กรุณากรอกจำนวน'];
        if ($price < 0) return ['error' => 'ราคาต้องไม่ติดลบ'];
        if ($fee < 0)   return ['error' => 'ค่าธรรมเนียมต้องไม่ติดลบ'];
        if (!$date)     return ['error' => 'กรุณาเลือกวันที่'];
        if ($cur === '' || !preg_match('/^[A-Z]{3}$/', $cur)) {
            return ['error' => 'สกุลเงินไม่ถูกต้อง'];
        }

        return [
            'ticker'   => $ticker,
            'market'   => $market,
            'side'     => $side,
            'quantity' => $qty,
            'price'    => $price,
            'fee'      => $fee,
            'currency' => $cur,
            'txn_date' => $date,
            'notes'    => $notes !== '' ? $notes : null,
        ];
    }

    private function fetchQuote(string $provider, string $key, string $ticker): array
    {
        switch ($provider) {
            case 'finnhub': {
                $r = $this->httpJson(
                    'https://finnhub.io/api/v1/quote?symbol=' . urlencode($ticker) . '&token=' . urlencode($key),
                    null, [], 'GET'
                );
                $c = isset($r['c']) ? (float)$r['c'] : 0;
                if ($c <= 0) throw new \RuntimeException('ไม่พบราคา (ticker อาจผิด หรือ quota หมด)');
                
                // Fetch basic metrics optionally
                $pe = null; $forwardPe = null; $peg = null; $pFcf = null; $eps = null;
                try {
                    $m = $this->httpJson(
                        'https://finnhub.io/api/v1/stock/metric?symbol=' . urlencode($ticker) . '&metric=all&token=' . urlencode($key),
                        null, [], 'GET'
                    );
                    $metric = $m['metric'] ?? [];
                    $pe = isset($metric['peBasicShare']) ? (float)$metric['peBasicShare'] : (isset($metric['peTTM']) ? (float)$metric['peTTM'] : null);
                    $forwardPe = isset($metric['peNormalized']) ? (float)$metric['peNormalized'] : null;
                    $peg = isset($metric['pegTTM']) ? (float)$metric['pegTTM'] : null;
                    $pFcf = isset($metric['pfcfShareTTM']) ? (float)$metric['pfcfShareTTM'] : null;
                    $eps = isset($metric['epsBasicShareTTM']) ? (float)$metric['epsBasicShareTTM'] : null;
                } catch (\Throwable $e) {}

                return [
                    'price'      => $c,
                    'prev_close' => isset($r['pc']) ? (float)$r['pc'] : null,
                    'currency'   => null,
                    'pe'         => $pe,
                    'forward_pe' => $forwardPe,
                    'peg'        => $peg,
                    'p_fcf'      => $pFcf,
                    'eps'        => $eps,
                ];
            }
            case 'alphavantage': {
                $r = $this->httpJson(
                    'https://www.alphavantage.co/query?function=GLOBAL_QUOTE&symbol=' . urlencode($ticker) . '&apikey=' . urlencode($key),
                    null, [], 'GET'
                );
                $q = $r['Global Quote'] ?? [];
                if (empty($q['05. price'])) throw new \RuntimeException('ไม่พบราคา (อาจเกิน rate limit)');
                
                // Fetch overview metrics optionally
                $pe = null; $forwardPe = null; $peg = null; $pFcf = null; $eps = null;
                try {
                    $ov = $this->httpJson(
                        'https://www.alphavantage.co/query?function=OVERVIEW&symbol=' . urlencode($ticker) . '&apikey=' . urlencode($key),
                        null, [], 'GET'
                    );
                    $pe = isset($ov['PERatio']) && $ov['PERatio'] !== 'None' ? (float)$ov['PERatio'] : null;
                    $forwardPe = isset($ov['ForwardPE']) && $ov['ForwardPE'] !== 'None' ? (float)$ov['ForwardPE'] : null;
                    $peg = isset($ov['PEGRatio']) && $ov['PEGRatio'] !== 'None' ? (float)$ov['PEGRatio'] : null;
                    $eps = isset($ov['EPS']) && $ov['EPS'] !== 'None' ? (float)$ov['EPS'] : null;
                } catch (\Throwable $e) {}

                return [
                    'price'      => (float)$q['05. price'],
                    'prev_close' => isset($q['08. previous close']) ? (float)$q['08. previous close'] : null,
                    'currency'   => null,
                    'pe'         => $pe,
                    'forward_pe' => $forwardPe,
                    'peg'        => $peg,
                    'p_fcf'      => $pFcf,
                    'eps'        => $eps,
                ];
            }
            case 'twelvedata': {
                $r = $this->httpJson(
                    'https://api.twelvedata.com/price?symbol=' . urlencode($ticker) . '&apikey=' . urlencode($key),
                    null, [], 'GET'
                );
                if (empty($r['price'])) throw new \RuntimeException('ไม่พบราคา');
                return [
                    'price'      => (float)$r['price'],
                    'prev_close' => null,
                    'currency'   => null,
                ];
            }
        }
        throw new \RuntimeException('Provider ไม่รองรับ');
    }

    private function httpJson(string $url, $body, array $headers = [], string $method = 'POST'): array
    {
        $ch = curl_init($url);
        $hdrs = array_merge(['Accept: application/json'], $headers);
        if ($body !== null) $hdrs[] = 'Content-Type: application/json';
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $hdrs,
            CURLOPT_CUSTOMREQUEST  => $method,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
        }
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false) throw new \RuntimeException('HTTP error: ' . $err);
        $data = json_decode($raw, true);
        if ($code >= 400) {
            $msg = is_array($data) ? ($data['error']['message'] ?? $data['error'] ?? $data['Note'] ?? $data['message'] ?? json_encode($data)) : $raw;
            if (is_array($msg)) $msg = json_encode($msg);
            throw new \RuntimeException('HTTP ' . $code . ': ' . $msg);
        }
        if (!is_array($data)) throw new \RuntimeException('ตอบกลับไม่ใช่ JSON');
        return $data;
    }
}
