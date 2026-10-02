<?php
// =====================================================
// controllers/DevController.php — pages for people building the interface
//
// Not reachable in production: every action answers 404 unless the app runs in
// development, so a forgotten route never ships a gallery to visitors.
// =====================================================

class DevController
{
    /** Every component in every state, the page docs/REDESIGN.md §3 points to. */
    public function components(): void
    {
        if (APP_ENV !== 'development') {
            Response::abort(404, 'ไม่พบหน้าที่ต้องการ');
        }

        $pageTitle = 'ชุด component';

        require ROOT . '/views/layout/header.php';
        require ROOT . '/views/dev/components.php';
        require ROOT . '/views/layout/footer.php';
    }
}
