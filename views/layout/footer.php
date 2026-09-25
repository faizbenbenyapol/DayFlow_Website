</div><!-- /.app-content -->
</main><!-- /.app-main -->

<!-- SweetAlert2 is not loaded here: app.js fetches it the first time something
     calls Swal.fire(), so page views that never open a dialog skip ~90KB. -->
<!-- CDN: Sortable.js — only the pages with drag-and-drop lists need it -->
<?php
$sortablePages = ['dashboard', 'notes', 'projects', 'settings', 'tasks'];
if (isset($pageScript) && in_array($pageScript, $sortablePages, true)):
?>
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js" integrity="sha384-eeLEhtwdMwD3X9y+8P3Cn7Idl/M+w8H4uZqkgD/2eJVkWIN1yKzEj6XegJ9dL3q0" crossorigin="anonymous"></script>
<?php endif; ?>
<!-- CDN: Chart.js (loaded only on finance page) -->
<?php if (isset($loadChartJs) && $loadChartJs): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js" integrity="sha384-e6nUZLBkQ86NJ6TVVKAeSaK8jWa3NhkYWZFomE39AvDbQWeie9PlQqM3pmYW5d1g" crossorigin="anonymous"></script>
<?php endif; ?>
<!-- CDN: PDF / File Tools libs (loaded only on file-tools page) -->
<?php if (isset($loadPdfLibs) && $loadPdfLibs): ?>
<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js" integrity="sha384-weMABwrltA6jWR8DDe9Jp5blk+tZQh7ugpCsF3JwSA53WZM9/14PjS5LAJNHNjAI" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/pdfjs-dist@3.11.174/build/pdf.min.js" integrity="sha384-/1qUCSGwTur9vjf/z9lmu/eCUYbpOTgSjmpbMQZ1/CtX2v/WcAIKqRv+U1DUCG6e" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js" integrity="sha384-+mbV2IY1Zk/X1p/nWllGySJSUN8uMs+gUAN10Or95UBH0fpj6GfKgPmgC5EXieXG" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/spark-md5@3.0.2/spark-md5.min.js" integrity="sha384-WAahC3S+69Co45zyyuhCjvdMwo7a42Yn0mM0IgZIUlYNebG24AVkPUltj3BQnM85" crossorigin="anonymous"></script>
<?php endif; ?>
<!-- CDN: QR Code generator (loaded only on transfer page) -->
<?php if (isset($loadQrLib) && $loadQrLib): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode-generator@1.4.4/qrcode.min.js" integrity="sha384-lQXOAyZwHXE55JFyrOMB7nY2Wv+m5ZWNtJcHrd1rceRQXAYNLak8ukN5TjBTcIwz" crossorigin="anonymous"></script>
<?php endif; ?>

<!-- Global JS -->
<script src="<?= APP_URL ?>/assets/js/actions.js?v=<?= @filemtime(PUBLIC_ROOT . '/assets/js/actions.js') ?>"></script>
<script src="<?= APP_URL ?>/assets/js/app.js?v=<?= @filemtime(PUBLIC_ROOT . '/assets/js/app.js') ?>"></script>

<script nonce="<?= h(Security::nonce()) ?>">
// Service worker: caches the static shell only (CSS/JS/fonts), never pages or
// API responses. Requires a secure context, so it is skipped on plain http
// except on localhost.
if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
        navigator.serviceWorker.register('<?= APP_URL ?>/sw.js').catch(function (err) {
            console.warn('Service worker registration failed:', err);
        });
    });
}
</script>

<!-- Page-specific JS -->
<?php if (isset($pageScript)):
    // Pages whose script comes in several files, loaded in this order after
    // the main one. They are classic scripts, so they share the page's globals.
    $pageScriptParts = [
        'settings'   => ['settings-two-factor', 'settings-push', 'shares'],
        'projects'   => ['projects-board', 'projects-team', 'projects-share'],
        'stocks'     => ['stocks-analysis', 'stocks-capital', 'stocks-screenshots'],
    ];
    foreach (array_merge([$pageScript], $pageScriptParts[$pageScript] ?? []) as $script): ?>
<script src="<?= APP_URL ?>/assets/js/<?= h($script) ?>.js?v=<?= @filemtime(PUBLIC_ROOT . '/assets/js/' . $script . '.js') ?>"></script>
<?php endforeach; endif; ?>

<script nonce="<?= h(Security::nonce()) ?>">
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
<script nonce="<?= h(Security::nonce()) ?>">
// Shared links keep their token in the URL/session. The native mobile Back
// action is intentionally left untouched so a shortcut can close back to the
// phone home screen; reopening the shortcut restores share mode automatically.
(function shareModeRefresh() {
    const INTERVAL = 60000;
    let due = false;

    const shouldHold = () => document.hidden
        || document.querySelector('.modal-backdrop.active')
        || document.querySelector('.swal2-container');

    // Reloading a tab nobody is looking at costs a full page render plus every
    // query behind it, so a hidden or busy tab defers until it is back in use.
    setInterval(function () {
        if (shouldHold()) { due = true; return; }
        window.location.reload();
    }, INTERVAL);

    document.addEventListener('visibilitychange', function () {
        if (!document.hidden && due && !shouldHold()) window.location.reload();
    });
})();
</script>
<?php endif; ?>

</body>
</html>
