<?php
// =====================================================
// controllers/HealthController.php - Service health probe
// =====================================================

class HealthController
{
    public function index(): void
    {
        try {
            DB::run('SELECT 1')->fetchColumn();
            Response::json([
                'ok' => true,
                'app' => APP_NAME,
                'database' => 'ok',
                'timestamp' => gmdate('c'),
            ]);
        } catch (Throwable $e) {
            Response::json([
                'ok' => false,
                'app' => APP_NAME,
                'database' => 'unavailable',
            ], 503);
        }
    }
}
