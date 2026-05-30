<?php
// =====================================================
// controllers/CalculatorController.php
// =====================================================

class CalculatorController
{
    public function index(): void
    {
        $pageTitle  = 'คำนวณ';
        $pageScript = 'calculator';
        $pageStyle  = 'calculator';

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/calculator/index.php';
        require ROOT . '/views/layout/footer.php';
    }
}
