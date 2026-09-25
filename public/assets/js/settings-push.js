// =====================================================
// settings-push.js — the browser-notification card on the settings page
// =====================================================

/* =====================================================
   Browser notifications (Web Push)
   Needs three things to line up: a service worker, permission from the user,
   and VAPID keys on the server. The card explains whichever one is missing.
===================================================== */
(function initPushNotifications() {
    const controls = document.getElementById('pushControls');
    if (!controls) return;

    const $id = id => document.getElementById(id);
    const show = (id, visible) => { const el = $id(id); if (el) el.style.display = visible ? 'block' : 'none'; };

    function setBadge(text, kind) {
        const badge = $id('pushBadge');
        if (!badge) return;
        badge.textContent = text;
        badge.className = 'badge ' + (kind || 'badge-gray');
    }

    function unavailable(reason) {
        setBadge('ใช้งานไม่ได้', 'badge-gray');
        show('pushControls', false);
        show('pushUnavailable', true);
        const el = $id('pushUnavailableReason');
        if (el) el.textContent = reason;
    }

    // The VAPID key travels as base64url but the browser wants raw bytes.
    function urlBase64ToUint8Array(base64) {
        const padded = (base64 + '='.repeat((4 - base64.length % 4) % 4)).replace(/-/g, '+').replace(/_/g, '/');
        const raw = atob(padded);
        return Uint8Array.from(raw, ch => ch.charCodeAt(0));
    }

    async function currentSubscription() {
        const registration = await navigator.serviceWorker.getRegistration();
        if (!registration) return null;
        return registration.pushManager.getSubscription();
    }

    async function render(config) {
        const subscription = await currentSubscription();
        const subscribed = !!subscription;

        show('pushControls', true);
        show('pushUnavailable', false);
        $id('pushDeviceCount').textContent = config.devices;
        $id('btnPushEnable').style.display = subscribed ? 'none' : 'inline-flex';
        $id('btnPushDisable').style.display = subscribed ? 'inline-flex' : 'none';
        $id('btnPushTest').style.display = subscribed ? 'inline-flex' : 'none';

        if (subscribed) setBadge('เปิดอยู่บนอุปกรณ์นี้', 'badge-success');
        else if (Notification.permission === 'denied') setBadge('ถูกบล็อกในเบราว์เซอร์', 'badge-danger');
        else setBadge('ปิดอยู่', 'badge-gray');
    }

    async function refresh() {
        if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
            unavailable('เบราว์เซอร์นี้ไม่รองรับการแจ้งเตือนแบบ Push');
            return null;
        }
        if (!window.isSecureContext) {
            unavailable('ต้องเปิดผ่าน HTTPS จึงจะใช้การแจ้งเตือนได้');
            return null;
        }

        try {
            const config = await apiFetch(BASE_URL + '/api/push/config');
            if (!config.enabled) {
                unavailable('ผู้ดูแลระบบยังไม่ได้ตั้งค่า VAPID keys บนเซิร์ฟเวอร์');
                return null;
            }
            await render(config);
            return config;
        } catch {
            unavailable('ตรวจสอบสถานะการแจ้งเตือนไม่สำเร็จ');
            return null;
        }
    }

    $id('btnPushEnable')?.addEventListener('click', async function () {
        const config = await apiFetch(BASE_URL + '/api/push/config').catch(() => null);
        if (!config || !config.enabled) { toast('ระบบแจ้งเตือนยังไม่พร้อมใช้งาน', 'danger'); return; }

        const permission = await Notification.requestPermission();
        if (permission !== 'granted') {
            toast('ต้องอนุญาตการแจ้งเตือนในเบราว์เซอร์ก่อน', 'danger');
            await refresh();
            return;
        }

        try {
            const registration = await navigator.serviceWorker.ready;
            const subscription = await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(config.public_key),
            });

            const json = subscription.toJSON();
            await apiFetch(BASE_URL + '/api/push/subscribe', {
                method: 'POST',
                body: JSON.stringify({ endpoint: json.endpoint, keys: json.keys }),
            });

            await refresh();
            toast('เปิดการแจ้งเตือนบนอุปกรณ์นี้แล้ว');
        } catch (err) {
            console.error('Push subscribe failed:', err);
            toast('เปิดการแจ้งเตือนไม่สำเร็จ', 'danger');
        }
    });

    $id('btnPushDisable')?.addEventListener('click', async function () {
        try {
            const subscription = await currentSubscription();
            if (subscription) {
                await apiFetch(BASE_URL + '/api/push/unsubscribe', {
                    method: 'POST',
                    body: JSON.stringify({ endpoint: subscription.endpoint }),
                });
                await subscription.unsubscribe();
            }
            await refresh();
            toast('ปิดการแจ้งเตือนบนอุปกรณ์นี้แล้ว');
        } catch (err) {
            console.error('Push unsubscribe failed:', err);
            toast('ปิดการแจ้งเตือนไม่สำเร็จ', 'danger');
        }
    });

    $id('btnPushTest')?.addEventListener('click', async function () {
        try {
            await apiFetch(BASE_URL + '/api/push/test', { method: 'POST' });
            toast('ส่งแจ้งเตือนทดสอบแล้ว');
        } catch (err) {
            toast(err.message || 'ส่งแจ้งเตือนไม่สำเร็จ', 'danger');
        }
    });

    refresh();
})();
