<?php
// =====================================================
// controllers/StocksController.php — the stocks page, transactions,
// portfolio figures, price refresh and AI analysis
//
// Watchlists, provider keys, capital flows and the portfolio screenshot have
// controllers of their own (Stock*Controller); quotes come from StockQuote.
// =====================================================

class StocksController
{
    /** A cached quote younger than this is not fetched again. */
    private const CACHE_COOLDOWN_SECONDS = 300;

    /** AI providers tried for an analysis, in order, until one answers. */
    private const ANALYSIS_PROVIDERS = ['gemini', 'openai', 'anthropic', 'kimi', 'openrouter'];

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
        if ($year < 2000 || $year > 2100) Response::json(['error' => 'ปีไม่ถูกต้อง'], 422);
        Response::json([
            'year' => $year,
            'series' => Stock::monthlyValueSeries($userId, $year),
        ]);
    }

    // ============================================================
    // PRICE REFRESH
    // ============================================================

    /** Refreshes quotes for the given tickers, or for every holding and watchlist entry. */
    public function apiRefresh(): void
    {
        $userId  = Auth::userId();
        $tickers = Request::rawInput('tickers', []);

        if (!is_array($tickers) || !$tickers) {
            $tickers = array_merge(
                array_column(Stock::portfolioForUser($userId)['holdings'], 'ticker'),
                array_column(Stock::getWatchlistsForUser($userId), 'ticker')
            );
        }
        $tickers = array_values(array_unique(array_filter(array_map('strtoupper', $tickers))));
        if (count($tickers) > 50) Response::json(['error' => 'ขอข้อมูลหุ้นได้ไม่เกิน 50 สัญลักษณ์ต่อครั้ง'], 422);
        if (!$tickers) Response::json(['ok' => true, 'updated' => [], 'skipped' => [], 'errors' => []]);

        $keyInfo = StockApiKey::getFirstAvailable($userId);
        if (!$keyInfo) {
            Response::json(['error' => 'กรุณาตั้งค่า API หุ้นก่อน (ตั้งค่า → API หุ้น)'], 422);
        }

        $updated = [];
        $skipped = [];
        $errors  = [];

        foreach ($tickers as $ticker) {
            if (!preg_match('/^[A-Z0-9.\-]+$/', $ticker)) {
                $errors[$ticker] = 'Ticker ไม่ถูกต้อง';
                continue;
            }
            if (self::isFresh(StockPriceCache::get($ticker))) {
                $skipped[] = $ticker;
                continue;
            }
            try {
                StockQuote::refresh($keyInfo['provider'], $keyInfo['key'], $ticker);
                $updated[] = $ticker;
            } catch (Throwable $e) {
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

    /** A cached quote that is recent and already carries valuation ratios. */
    private static function isFresh(?array $cached): bool
    {
        if (!$cached || !$cached['fetched_at']) return false;

        $hasMetrics = $cached['pe_ratio'] !== null || $cached['forward_pe'] !== null
            || $cached['peg_ratio'] !== null || $cached['p_fcf_ratio'] !== null || $cached['eps'] !== null;
        return $hasMetrics && (time() - strtotime($cached['fetched_at'])) < self::CACHE_COOLDOWN_SECONDS;
    }

    // ============================================================
    // AI STOCK ANALYSIS
    // ============================================================

    /** Asks each configured AI provider in turn until one returns a usable analysis. */
    public function apiAnalyze(): void
    {
        $userId = Auth::userId();
        $ticker = strtoupper(trim((string)Request::rawInput('ticker', '')));
        $market = Request::rawInput('market', 'US');

        if ($ticker === '' || !preg_match('/^[A-Z0-9.\-]{1,20}$/', $ticker)) {
            Response::json(['error' => 'กรุณากรอกสัญลักษณ์หุ้นให้ถูกต้อง'], 422);
        }

        $availableKeys = [];
        foreach (self::ANALYSIS_PROVIDERS as $p) {
            $key = AiKey::get($userId, $p);
            if ($key !== '') $availableKeys[] = ['provider' => $p, 'key' => $key];
        }
        if (!$availableKeys) {
            Response::json(['error' => 'กรุณาตั้งค่า API Key สำหรับ AI ก่อนใช้งาน ในส่วน "API สำหรับการวิเคราะห์หุ้นด้วย AI" ในหน้า ตั้งค่า → API หุ้น'], 422);
        }

        // A live price makes the analysis far better, but it is optional.
        $keyInfo = StockApiKey::getFirstAvailable($userId);
        if ($keyInfo) {
            try {
                StockQuote::refresh($keyInfo['provider'], $keyInfo['key'], $ticker);
            } catch (Throwable) {}
        }

        $prompt = self::analysisPrompt($ticker, $market, StockPriceCache::get($ticker));

        $lastError = '';
        foreach ($availableKeys as ['provider' => $provider, 'key' => $apiKey]) {
            try {
                $parsed = LlmClient::extractJson(LlmClient::complete($provider, $apiKey, $prompt));
                if ($parsed) {
                    Response::json(['ok' => true, 'result' => $parsed, 'provider' => $provider]);
                }
                $lastError = 'AI ' . $provider . ' ส่งข้อมูลมาไม่ถูกต้อง';
            } catch (Throwable $e) {
                // A rate limit or a revoked key on one provider moves on to the next.
                $lastError = $e->getMessage();
            }
        }

        Response::json(['error' => 'เกิดข้อผิดพลาดในการวิเคราะห์ด้วย AI: ' . $lastError], 500);
    }

    private static function analysisPrompt(string $ticker, string $market, ?array $cached): string
    {
        $currentPriceText = '';
        if ($cached) {
            $currentPriceText = 'ราคาตลาดจริงล่าสุดของหุ้นตัวนี้ ณ ปัจจุบันในระบบ: ' . $cached['price'] . ' ' . ($cached['currency'] ?: 'USD') . ' (ราคาอ้างอิงปิดวันก่อนหน้า: ' . ($cached['prev_close'] ?: '—') . ' ' . ($cached['currency'] ?: 'USD') . ')';
        }

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

        return $prompt;
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
}
