/* =====================================================
   standalone.js
   What a page outside the app needs and nothing more: sign-in, a shared file,
   an expired link, an error page, the offline notice. None of them load app.js.

   - theme   data-theme-pref="auto" follows the device, live
   - day     data-day is filled in from the clock when the server did not say
             (the offline page is a static file, so it cannot)
   - buttons data-reload reloads; data-back goes back, or to the address in
             data-back when this tab has no history (a link opened in a new tab)
===================================================== */
(function standalone() {
    const root = document.documentElement;

    if (root.dataset.themePref === 'auto') {
        const query = window.matchMedia('(prefers-color-scheme: dark)');
        const apply = () => root.setAttribute('data-theme', query.matches ? 'dark' : 'light');
        apply();
        query.addEventListener('change', apply);
    }

    if (!root.dataset.day) {
        root.dataset.day = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'][new Date().getDay()];
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-reload], [data-back]');
        if (!button) return;

        if (button.hasAttribute('data-reload')) {
            window.location.reload();
        } else if (window.history.length > 1) {
            window.history.back();
        } else {
            window.location.href = button.getAttribute('data-back') || '/';
        }
    });
})();
