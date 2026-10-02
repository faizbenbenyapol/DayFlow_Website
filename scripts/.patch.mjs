import fs from 'node:fs';
let s = fs.readFileSync('scripts/.edit.mjs', 'utf8');
const a = s.indexOf("edit('public/assets/js/file-tools.js'");
const head = s.slice(0, a);
const tail = `edit('public/assets/js/file-tools.js', t => {
    const old = \`document.querySelectorAll('.ft-tab').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.ft-tab').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.ft-panel').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.querySelector(\\`.ft-panel[data-panel="\\${btn.dataset.tab}"]\\`)?.classList.add('active');
    });
});\`;
    const fresh = \`{
    const tabs = Array.from(document.querySelectorAll('.ft-tab'));
    const select = (tab, focus) => {
        tabs.forEach(b => {
            b.setAttribute('aria-selected', String(b === tab));
            b.tabIndex = b === tab ? 0 : -1;
        });
        document.querySelectorAll('.ft-panel').forEach(p => p.classList.toggle('active', p.dataset.panel === tab.dataset.tab));
        if (focus) tab.focus();
    };
    tabs.forEach((tab, i) => {
        tab.tabIndex = tab.getAttribute('aria-selected') === 'true' ? 0 : -1;
        tab.addEventListener('click', () => select(tab));
        tab.addEventListener('keydown', e => {
            const step = e.key === 'ArrowRight' ? 1 : e.key === 'ArrowLeft' ? -1 : 0;
            if (!step) return;
            e.preventDefault();
            select(tabs[(i + step + tabs.length) % tabs.length], true);
        });
    });

    // Ties each label to the field that follows it, so a click on the label reaches the field.
    let n = 0;
    document.querySelectorAll('.form-group').forEach(group => {
        const label = group.querySelector('label.form-label:not([for])');
        const field = group.querySelector('input:not([type="hidden"]), select, textarea');
        if (!label || !field) return;
        if (!field.id) field.id = 'ft-field-' + (++n);
        label.htmlFor = field.id;
    });
}\`;
    return swap(t, old, fresh);
});
`;
fs.writeFileSync('scripts/.edit.mjs', head + tail);
