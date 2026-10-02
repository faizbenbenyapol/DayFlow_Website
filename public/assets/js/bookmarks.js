/* =====================================================
   bookmarks.js — links kept in one place
===================================================== */

let bookmarks = [];

document.addEventListener('DOMContentLoaded', loadBookmarks);

async function loadBookmarks() {
    const grid = document.getElementById('bookmarksGrid');
    try {
        const data = await apiFetch(BASE_URL + '/api/bookmarks');
        bookmarks = data.bookmarks || [];
        grid.removeAttribute('aria-busy');
        renderBookmarks();
    } catch {
        grid.removeAttribute('aria-busy');
        document.getElementById('bookmarkTally').textContent = 'โหลดลิงก์ไม่ได้';
        grid.innerHTML = '<div class="alert alert-danger" role="alert">โหลดลิงก์ไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
            + '<button type="button" class="btn btn-sm" data-act="loadBookmarks">ลองอีกครั้ง</button></div>';
    }
}

/** The site's own name, for showing beside the title: example.com, not https://www.example.com/a?b=c */
function bookmarkHost(url) {
    try { return new URL(url).host.replace(/^www\./, ''); } catch { return url; }
}

function bookmarkRow(b) {
    const title = escHtml(b.title);
    return '<li class="ruled-row bm-row">'
        + '<span class="grow"><a class="title" href="' + escHtml(b.url) + '" target="_blank" rel="noopener noreferrer">' + title + '<span class="sr-only"> (เปิดในแท็บใหม่)</span></a>'
        + '<span class="meta" title="' + escHtml(b.url) + '">' + escHtml(bookmarkHost(b.url)) + '</span></span>'
        + '<button type="button" class="icon-btn sm" data-act="deleteBookmark" data-args="[' + b.id + ']" aria-label="ลบลิงก์: ' + title + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
        + '</li>';
}

function renderBookmarks() {
    const grid = document.getElementById('bookmarksGrid');
    const tally = document.getElementById('bookmarkTally');

    // Offered when typing a category, so the same group is not spelled two ways.
    const categories = [...new Set(bookmarks.map(b => b.category || 'ทั่วไป'))];
    document.getElementById('bookmarkCategories').innerHTML = categories.map(c => '<option value="' + escHtml(c) + '"></option>').join('');

    if (!bookmarks.length) {
        tally.textContent = 'ยังไม่มีลิงก์';
        grid.innerHTML = '<div class="empty-state"><p class="empty-state-title">ยังไม่มีลิงก์สำคัญ</p>'
            + '<p class="empty-state-text">เก็บเว็บที่เปิดบ่อยไว้ตรงนี้ เช่น อีเมล ไดรฟ์ หรือระบบของที่ทำงาน แล้วกดเปิดได้ทันที</p>'
            + '<button type="button" class="btn btn-primary" data-act="openBookmark">เพิ่มลิงก์แรก</button></div>';
        return;
    }

    tally.textContent = bookmarks.length + ' ลิงก์ใน ' + categories.length + ' หมวด';
    grid.innerHTML = categories.map(category => {
        const rows = bookmarks.filter(b => (b.category || 'ทั่วไป') === category);
        return '<section class="sec" aria-label="' + escHtml(category) + '">'
            + '<div class="sec-head"><h2>' + escHtml(category) + '<span class="count">' + rows.length + '</span></h2></div>'
            + '<ul class="ruled-list">' + rows.map(bookmarkRow).join('') + '</ul></section>';
    }).join('');
}

function bookmarkError(message) {
    const line = document.getElementById('bookmarkError');
    line.textContent = message;
    line.hidden = message === '';
}

function openBookmark() {
    document.getElementById('bookmarkName').value = '';
    document.getElementById('bookmarkUrl').value = '';
    document.getElementById('bookmarkCategory').value = 'ทั่วไป';
    bookmarkError('');
    openModal('bookmarkModal');
    document.getElementById('bookmarkName').focus();
}

async function saveBookmark() {
    const title = document.getElementById('bookmarkName').value.trim();
    const url = document.getElementById('bookmarkUrl').value.trim();
    const category = document.getElementById('bookmarkCategory').value.trim();

    if (!title) { bookmarkError('ใส่ชื่อเว็บก่อน'); return; }
    if (!/^https?:\/\/\S+\.\S+/i.test(url)) { bookmarkError('ที่อยู่เว็บต้องขึ้นต้นด้วย http:// หรือ https://'); return; }

    try {
        await apiFetch(BASE_URL + '/api/bookmarks', { method: 'POST', body: JSON.stringify({ title, url, category }) });
        closeModal('bookmarkModal');
        await loadBookmarks();
        toast('บันทึกลิงก์แล้ว');
    } catch (err) {
        bookmarkError(err.message || 'บันทึกไม่สำเร็จ ลองอีกครั้ง');
    }
}

async function deleteBookmark(id) {
    const bookmark = bookmarks.find(b => b.id === id);
    if (!await confirmAction('ลบ "' + (bookmark ? bookmark.title : 'ลิงก์นี้') + '" แล้วกู้คืนไม่ได้', 'ลบลิงก์', 'ลบลิงก์นี้?')) return;
    try {
        await apiFetch(BASE_URL + '/api/bookmarks/' + id, { method: 'DELETE' });
        await loadBookmarks();
        toast('ลบลิงก์แล้ว');
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
    }
}
