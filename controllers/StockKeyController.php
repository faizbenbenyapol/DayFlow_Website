<?php
// =====================================================
// controllers/StockKeyController.php — price-provider API keys
// =====================================================

class StockKeyController
{
    public function apiList(): void
    {
        Response::json([
            'keys'      => StockApiKey::listForUser(Auth::userId()),
            'providers' => StockApiKey::PROVIDERS,
        ]);
    }

    public function apiSave(): void
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

    /** Proves a key works by quoting AAPL with it; the saved key when none is sent. */
    public function apiTest(): void
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
            $q = StockQuote::fetch($provider, $apiKey, 'AAPL');
            Response::json(['ok' => true, 'message' => 'ใช้งานได้ · AAPL = ' . $q['price']]);
        } catch (Throwable $e) {
            Response::json(['error' => $e->getMessage()], 400);
        }
    }

    public function apiDelete(string $provider): void
    {
        StockApiKey::delete(Auth::userId(), $provider);
        Response::json(['ok' => true]);
    }
}
