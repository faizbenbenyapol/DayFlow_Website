</div><!-- /.app-content -->
</main><!-- /.app-main -->

<!-- CDN: SweetAlert2 -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js"></script>
<!-- CDN: Sortable.js -->
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<!-- CDN: Chart.js (loaded only on finance page) -->
<?php if (isset($loadChartJs) && $loadChartJs): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<?php endif; ?>
<!-- CDN: PDF / File Tools libs (loaded only on file-tools page) -->
<?php if (isset($loadPdfLibs) && $loadPdfLibs): ?>
<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/spark-md5@3.0.2/spark-md5.min.js"></script>
<?php endif; ?>
<!-- CDN: QR Code generator (loaded only on transfer page) -->
<?php if (isset($loadQrLib) && $loadQrLib): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js"></script>
<?php endif; ?>

<!-- Global JS -->
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= @filemtime(ROOT . '/assets/js/app.js') ?>"></script>

<!-- Page-specific JS -->
<?php if (isset($pageScript)): ?>
<script src="<?= APP_URL ?>/assets/js/<?= h($pageScript) ?>.js?v=<?= @filemtime(ROOT . '/assets/js/' . $pageScript . '.js') ?>"></script>
<?php if ($pageScript === 'settings'): ?>
<script src="<?= APP_URL ?>/assets/js/shares.js?v=<?= @filemtime(ROOT . '/assets/js/shares.js') ?>"></script>
<?php endif; ?>
<?php endif; ?>

<script>
// Mobile sidebar toggle and hamburger icon animation
(function() {
    const btn     = document.getElementById('menuToggle');
    const sidebar = document.getElementById('appSidebar');
    if (!btn || !sidebar) return;

    btn.addEventListener('click', function() {
        const isOpen = sidebar.classList.toggle('open');
        btn.classList.toggle('active', isOpen);
        btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });

    // Close sidebar when nav item clicked on mobile
    sidebar.querySelectorAll('.nav-item').forEach(function(el) {
        el.addEventListener('click', function() {
            sidebar.classList.remove('open');
            btn.classList.remove('active');
            btn.setAttribute('aria-expanded', 'false');
        });
    });

    // Close sidebar when clicking outside
    document.addEventListener('click', function(e) {
        if (window.innerWidth > 768) return;
        if (!sidebar.contains(e.target) && !btn.contains(e.target)) {
            sidebar.classList.remove('open');
            btn.classList.remove('active');
            btn.setAttribute('aria-expanded', 'false');
        }
    });

    // Touch device soft keyboard scroll-into-view helper
    if ('ontouchstart' in window || navigator.maxTouchPoints > 0) {
        document.addEventListener('focusin', function(e) {
            const el = e.target;
            if (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT') {
                setTimeout(function() {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }, 280);
            }
        });
    }
})();
</script>

<?php if (Auth::isReadOnly()): ?>
<script>
// Shared links keep their token in the URL/session. The native mobile Back
// action is intentionally left untouched so a shortcut can close back to the
// phone home screen; reopening the shortcut restores share mode automatically.
setTimeout(() => {
    window.location.reload();
}, 30000);
</script>
<?php endif; ?>

</body>
</html>
