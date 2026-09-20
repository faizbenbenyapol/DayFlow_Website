<?php
// =====================================================
// controllers/ReviewController.php — weekly / monthly review
// =====================================================

class ReviewController
{
    private const PERIODS = ['week', 'month'];

    public function index(): void
    {
        $pageTitle  = 'สรุปผล';
        $pageStyle  = 'review';
        $pageScript = 'review';

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/review/index.php';
        require ROOT . '/views/layout/footer.php';
    }

    public function apiSummary(): void
    {
        $userId = Auth::userId();
        $period = (string)Request::query('period', 'week');
        if (!in_array($period, self::PERIODS, true)) $period = 'week';

        $warnings = [];
        $review   = Review::build($userId, $period, $warnings);

        Response::json($review + [
            'meta' => [
                'generated_at' => gmdate('c'),
                'partial'      => $warnings !== [],
                'warnings'     => array_values(array_unique($warnings)),
            ],
        ]);
    }
}
