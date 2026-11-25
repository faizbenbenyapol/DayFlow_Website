/* =====================================================
   notifications.js — กระดิ่งแจ้งเตือนที่แถบบนของทุกหน้า
   นับที่ยังไม่อ่านทุก 60 วินาทีเฉพาะตอนแท็บเปิดอยู่ (ไม่เปลืองเครื่องและเซิร์ฟเวอร์)
   หน้าที่ไม่มีกระดิ่ง (แขก หน้าอ่านอย่างเดียว) สคริปต์นี้ไม่ทำอะไร
===================================================== */

const NOTIF_POLL_MS = 60000;
let notifTimer = null;

/** แสดงจำนวนที่ยังไม่อ่านบนกระดิ่ง (0 = ซ่อนตัวเลข) และอัปเดตป้ายสำหรับโปรแกรมอ่านหน้าจอ */
function setNotifCount(unread) {
    const badge = document.getElementById('notifCount');
    const bell = document.getElementById('notifBell');
    if (!badge || !bell) return;
    badge.hidden = unread < 1;
    badge.textContent = unread > 99 ? '99+' : String(unread);
    bell.setAttribute('aria-label', unread > 0 ? 'การแจ้งเตือน ' + unread + ' รายการที่ยังไม่อ่าน' : 'การแจ้งเตือน');
}

/** ถามจำนวนที่ยังไม่อ่านหนึ่งครั้ง ข้ามเมื่อแท็บไม่ได้เปิดอยู่ */
async function pollNotifCount() {
    if (document.visibilityState !== 'visible') return;
    try {
        const data = await apiFetch(BASE_URL + '/api/notifications/unread');
        if (data) setNotifCount(data.unread || 0);
    } catch (e) { /* เครือข่ายหลุดชั่วคราว: รอบถัดไปลองใหม่ */ }
}

/** เวลาแบบอ่านง่าย: "เมื่อกี้" "12 นาทีที่แล้ว" "เมื่อวาน" หรือวันที่ */
function notifWhen(stamp) {
    const then = new Date(String(stamp).replace(' ', 'T'));
    const minutes = Math.round((Date.now() - then.getTime()) / 60000);
    if (minutes < 1) return 'เมื่อกี้';
    if (minutes < 60) return minutes + ' นาทีที่แล้ว';
    if (minutes < 24 * 60) return Math.round(minutes / 60) + ' ชั่วโมงที่แล้ว';
    if (minutes < 48 * 60) return 'เมื่อวาน';
    return then.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' });
}

/** วาดรายการในแผง: จุดแดง = ยังไม่อ่าน กดแล้วทำเครื่องหมายอ่านและไปที่ลิงก์ */
function renderNotifList(items) {
    const list = document.getElementById('notifList');
    if (!items.length) {
        list.innerHTML = '<p class="notif-empty">ยังไม่มีการแจ้งเตือน เมื่อมีคนมอบหมายงานหรือกล่าวถึงคุณ จะขึ้นที่นี่</p>';
        return;
    }
    list.innerHTML = items.map(item =>
        '<a class="notif-row' + (item.read ? '' : ' unread') + '" href="' + escHtml(BASE_URL + (item.url || '/')) + '"'
        + ' data-act="openNotification" data-args="[' + Number(item.id) + ']">'
        + '<span class="notif-what">' + escHtml(item.title) + '</span>'
        + '<span class="notif-where">' + (item.body ? escHtml(item.body) + ' · ' : '') + escHtml(notifWhen(item.created_at)) + '</span>'
        + '</a>').join('');
}

/** เปิด/ปิดแผงแจ้งเตือน เปิดแล้วโหลดรายการล่าสุด */
async function toggleNotifications() {
    const panel = document.getElementById('notifPanel');
    const bell = document.getElementById('notifBell');
    const open = panel.hidden;
    panel.hidden = !open;
    bell.setAttribute('aria-expanded', String(open));
    if (!open) return;
    document.getElementById('notifList').innerHTML = '<div class="skel-row"><span class="skel skel-w-60"></span></div>';
    try {
        const data = await apiFetch(BASE_URL + '/api/notifications');
        setNotifCount(data.unread || 0);
        renderNotifList(data.items || []);
    } catch (e) {
        document.getElementById('notifList').innerHTML = '<p class="notif-empty">โหลดการแจ้งเตือนไม่ได้ ลองใหม่อีกครั้ง</p>';
    }
}

/** ทำเครื่องหมายว่าอ่านทั้งหมด แล้ววาดใหม่ */
async function markAllNotificationsRead() {
    try {
        const data = await apiFetch(BASE_URL + '/api/notifications/read', { method: 'POST', body: JSON.stringify({ all: true }) });
        setNotifCount(data.unread || 0);
        document.querySelectorAll('#notifList .notif-row.unread').forEach(row => row.classList.remove('unread'));
    } catch (e) {
        toast(e.message, 'error');
    }
}

/** กดรายการ: ทำเครื่องหมายว่าอ่าน (ไม่รอ) แล้วไปตามลิงก์ของแถวนั้น */
function openNotification(id) {
    apiFetch(BASE_URL + '/api/notifications/read', { method: 'POST', body: JSON.stringify({ ids: [id] }), keepalive: true }).catch(() => {});
    const row = document.querySelector('#notifList [data-args="[' + Number(id) + ']"]');
    if (row) window.location.href = row.getAttribute('href');
}

document.addEventListener('DOMContentLoaded', () => {
    if (!document.getElementById('notifBell')) return;
    pollNotifCount();
    notifTimer = setInterval(pollNotifCount, NOTIF_POLL_MS);
    document.addEventListener('visibilitychange', pollNotifCount);
    // ปิดแผงเมื่อกดข้างนอกหรือกด Esc
    document.addEventListener('click', e => {
        const panel = document.getElementById('notifPanel');
        if (!panel.hidden && !e.target.closest('#notifPanel, #notifBell')) toggleNotifications();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape' && !document.getElementById('notifPanel').hidden) {
            toggleNotifications();
            document.getElementById('notifBell').focus();
        }
    });
});
