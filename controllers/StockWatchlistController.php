<?php
// =====================================================
// controllers/StockWatchlistController.php — tickers followed without holding them
// =====================================================

class StockWatchlistController
{
    public function apiList(): void
    {
        Response::json(['watchlists' => Stock::getWatchlistsForUser(Auth::userId())]);
    }

    public function apiToggle(): void
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
}
