// =====================================================
// settings.js — Settings page
// =====================================================
(function () {
    'use strict';
    const $ = (s, c) => (c || document).querySelector(s);
    const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

    // ---------- Tabs ----------
    function initTabs() {
        const tabs = $$('.settings-tab');
        const panes = $$('.settings-pane');
        tabs.forEach(btn => btn.addEventListener('click', () => {
            const name = btn.dataset.tab;
            tabs.forEach(t => {
                const active = t === btn;
                t.classList.toggle('active', active);
                t.classList.toggle('btn-primary', active);
                t.classList.toggle('btn-ghost', !active);
            });
            panes.forEach(p => p.style.display = p.id === 'tab-' + name ? 'block' : 'none');
            try { localStorage.setItem('settings_last_tab', name); } catch (_) {}
        }));
        // Remember last tab
        try {
            const last = localStorage.getItem('settings_last_tab');
            if (last) {
                const btn = tabs.find(t => t.dataset.tab === last);
                if (btn) btn.click();
            }
        } catch (_) {}
    }

    // ---------- Remembered devices ----------
    function initDevices() {
        const list = $('#deviceList');
        if (!list) return;
        let loaded = false;

        const formatDate = value => {
            if (!value) return 'ยังไม่มีข้อมูล';
            const date = new Date(String(value).replace(' ', 'T'));
            return Number.isNaN(date.getTime()) ? String(value) : date.toLocaleString('th-TH', {
                dateStyle: 'medium', timeStyle: 'short'
            });
        };
        const deviceName = ua => {
            const value = String(ua || '');
            if (!value) return 'ไม่ทราบอุปกรณ์';
            if (/iphone|ipad/i.test(value)) return 'iPhone / iPad';
            if (/android/i.test(value)) return 'Android';
            if (/windows/i.test(value)) return 'Windows';
            if (/macintosh|mac os/i.test(value)) return 'Mac';
            if (/linux/i.test(value)) return 'Linux';
            return 'เว็บเบราว์เซอร์';
        };
        const browserName = ua => {
            const value = String(ua || '');
            if (/edg\//i.test(value)) return 'Microsoft Edge';
            if (/chrome\//i.test(value)) return 'Google Chrome';
            if (/firefox\//i.test(value)) return 'Mozilla Firefox';
            if (/safari\//i.test(value) && !/chrome\//i.test(value)) return 'Safari';
            return 'เบราว์เซอร์อื่น';
        };
        const render = devices => {
            if (!devices.length) {
                list.innerHTML = '<div class="empty-state"><div class="empty-state-title">ยังไม่มีอุปกรณ์ที่จำไว้</div><div class="empty-state-text">ครั้งถัดไปที่เข้าสู่ระบบ ให้เลือก “จดจำอุปกรณ์นี้”</div></div>';
                return;
            }
            list.innerHTML = devices.map(device => `
                <div class="device-row ${device.is_current ? 'is-current' : ''}">
                    <div class="device-icon" aria-hidden="true">⌁</div>
                    <div class="device-main">
                        <div class="device-title">${escHtml(deviceName(device.user_agent))} <span class="device-browser">${escHtml(browserName(device.user_agent))}</span>${device.is_current ? '<span class="device-current">อุปกรณ์นี้</span>' : ''}</div>
                        <div class="device-meta">IP ${escHtml(device.ip_address || 'ไม่ทราบ')} · ใช้งานล่าสุด ${escHtml(formatDate(device.last_used_at || device.created_at))}</div>
                        <div class="device-meta">หมดอายุ ${escHtml(formatDate(device.expires_at))}</div>
                    </div>
                    <button class="btn btn-ghost btn-sm device-revoke" type="button" data-device-id="${Number(device.id)}">ยกเลิก</button>
                </div>
            `).join('');
            $$('.device-revoke', list).forEach(button => button.addEventListener('click', async () => {
                const ok = await confirmAction('อุปกรณ์นี้จะต้องเข้าสู่ระบบใหม่ในครั้งถัดไป', 'ยกเลิกอุปกรณ์', 'ยืนยันการยกเลิก');
                if (!ok) return;
                button.disabled = true;
                try {
                    await apiFetch(BASE_URL + '/api/settings/devices/' + encodeURIComponent(button.dataset.deviceId), { method: 'DELETE' });
                    toast('ยกเลิกอุปกรณ์แล้ว');
                    loaded = false;
                    await load();
                } catch (err) {
                    button.disabled = false;
                    toast(err.message || 'ยกเลิกอุปกรณ์ไม่สำเร็จ', 'danger');
                }
            }));
        };
        const load = async () => {
            list.innerHTML = '<div class="loading-state">กำลังโหลดรายการอุปกรณ์…</div>';
            try {
                const data = await apiFetch(BASE_URL + '/api/settings/devices');
                render(Array.isArray(data.devices) ? data.devices : []);
                loaded = true;
            } catch (err) {
                list.innerHTML = '<div class="empty-state"><div class="empty-state-title">โหลดรายการไม่สำเร็จ</div><button class="btn btn-ghost btn-sm" id="btnRetryDevices" type="button">ลองใหม่</button></div>';
                $('#btnRetryDevices')?.addEventListener('click', load);
            }
        };
        $$('.settings-tab').forEach(tab => tab.addEventListener('click', () => {
            if (tab.dataset.tab === 'devices' && !loaded) load();
        }));
        $('#btnRevokeOtherDevices')?.addEventListener('click', async () => {
            const ok = await confirmAction('อุปกรณ์อื่นทั้งหมดจะต้องเข้าสู่ระบบใหม่ โดยอุปกรณ์นี้จะยังใช้งานต่อได้', 'ออกจากอุปกรณ์อื่น', 'ยืนยัน');
            if (!ok) return;
            try {
                const data = await apiFetch(BASE_URL + '/api/settings/devices/revoke-others', { method: 'POST' });
                toast(`ยกเลิกอุปกรณ์อื่นแล้ว ${Number(data.revoked || 0)} รายการ`);
                loaded = false;
                await load();
            } catch (err) { toast(err.message || 'ดำเนินการไม่สำเร็จ', 'danger'); }
        });
    }

    // ---------- Profile ----------
    async function saveProfile() {
        const body = {
            display_name: $('#profileName').value.trim(),
            email:        $('#profileEmail').value.trim(),
        };
        try {
            await apiFetch(BASE_URL + '/api/settings/profile', { method: 'POST', body: JSON.stringify(body) });
            toast('บันทึกโปรไฟล์แล้ว');
        } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
    }

    // ---------- Theme ----------
    async function setTheme(theme) {
        try {
            await apiFetch(BASE_URL + '/api/settings/theme', { method: 'POST', body: JSON.stringify({ theme }) });
            // 'auto' is a preference; resolve it to a real palette for this page.
            const root = document.documentElement;
            root.dataset.themePref = theme;
            root.setAttribute('data-theme', theme === 'auto'
                ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light')
                : theme);
            toast('เปลี่ยนธีมแล้ว');
        } catch (err) { toast('บันทึกธีมไม่สำเร็จ', 'danger'); }
    }

    // ---------- Weekday colour ----------
    async function setDayColor(box) {
        const enabled = box.checked;
        try {
            await apiFetch(BASE_URL + '/api/settings/day-color', { method: 'POST', body: JSON.stringify({ enabled }) });
            document.documentElement.dataset.daycolor = enabled ? 'on' : 'off';
            toast(enabled ? 'เปิดสีประจำวันแล้ว' : 'ปิดสีประจำวันแล้ว');
        } catch (err) {
            box.checked = !enabled;
            toast('บันทึกไม่สำเร็จ', 'danger');
        }
    }

    // ---------- Password ----------
    function scorePassword(pw) {
        if (!pw) return { score: 0, label: 'อย่างน้อย 8 ตัวอักษร' };
        let score = 0;
        if (pw.length >= 8)  score++;
        if (pw.length >= 12) score++;
        if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
        if (/\d/.test(pw)) score++;
        if (/[^A-Za-z0-9]/.test(pw)) score++;
        const labels = ['อ่อนมาก', 'อ่อน', 'พอใช้', 'ดี', 'แข็งแรง', 'แข็งแรงมาก'];
        return { score, label: labels[score] || labels[0] };
    }

    function initPasswordForm() {
        // Show/hide toggles
        $$('.pw-toggle').forEach(btn => btn.addEventListener('click', () => {
            const inp = document.getElementById(btn.dataset.target);
            if (!inp) return;
            inp.type = inp.type === 'password' ? 'text' : 'password';
            btn.textContent = inp.type === 'password' ? 'แสดง' : 'ซ่อน';
        }));

        // Strength meter
        const newPw = $('#pwNew'), fill = $('#pwStrengthFill'), txt = $('#pwStrengthText');
        newPw?.addEventListener('input', () => {
            const { score, label } = scorePassword(newPw.value);
            const pct = (score / 5) * 100;
            fill.style.width = pct + '%';
            const colors = ['#c0392b', '#c0392b', '#c07a00', '#c07a00', '#27ae60', '#27ae60'];
            fill.style.background = colors[score] || colors[0];
            txt.textContent = newPw.value ? `ระดับ: ${label}` : 'อย่างน้อย 8 ตัวอักษร';
        });

        // Match hint
        const confirm = $('#pwConfirm'), hint = $('#pwMatchHint');
        const updateMatch = () => {
            if (!confirm.value) { hint.textContent = ''; hint.style.color = ''; return; }
            if (confirm.value === newPw.value) {
                hint.textContent = '✓ รหัสผ่านตรงกัน'; hint.style.color = 'var(--color-success)';
            } else {
                hint.textContent = '✗ รหัสผ่านไม่ตรงกัน'; hint.style.color = 'var(--color-danger)';
            }
        };
        confirm?.addEventListener('input', updateMatch);
        newPw?.addEventListener('input', updateMatch);

        // Submit
        $('#btnChangePassword')?.addEventListener('click', async () => {
            const current = $('#pwCurrent').value;
            const nw = newPw.value;
            const cf = confirm.value;
            if (!current || !nw || !cf) { toast('กรุณากรอกข้อมูลให้ครบ', 'danger'); return; }
            if (nw.length < 8) { toast('รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร', 'danger'); return; }
            if (nw !== cf) { toast('รหัสผ่านใหม่ไม่ตรงกัน', 'danger'); return; }
            if (nw === current) { toast('รหัสผ่านใหม่ต้องต่างจากรหัสผ่านปัจจุบัน', 'danger'); return; }

            try {
                await apiFetch(BASE_URL + '/api/settings/password', {
                    method: 'POST',
                    body: JSON.stringify({ current_password: current, new_password: nw, confirm_password: cf })
                });
                $('#pwCurrent').value = ''; newPw.value = ''; confirm.value = '';
                fill.style.width = '0%'; txt.textContent = 'อย่างน้อย 8 ตัวอักษร';
                hint.textContent = '';
                if (window.Swal) Swal.fire({ icon: 'success', title: 'เปลี่ยนรหัสผ่านแล้ว', timer: 1500, showConfirmButton: false });
                else toast('เปลี่ยนรหัสผ่านแล้ว');
            } catch (err) {
                toast(err.message || 'เปลี่ยนรหัสผ่านไม่สำเร็จ', 'danger');
            }
        });
    }

    // ---------- Timezone ----------
    function initTimezone() {
        const sel = $('#timezoneSelect');
        const clock = $('#tzCurrentTime');
        if (!sel || !clock) return;
        const update = () => {
            try {
                clock.textContent = new Date().toLocaleString('th-TH', {
                    timeZone: sel.value, dateStyle: 'full', timeStyle: 'medium'
                });
            } catch (_) { clock.textContent = new Date().toString(); }
        };
        update();
        setInterval(update, 1000);
        sel.addEventListener('change', update);

        $('#btnSaveTimezone')?.addEventListener('click', async () => {
            try {
                await apiFetch(BASE_URL + '/api/settings/timezone', {
                    method: 'POST', body: JSON.stringify({ timezone: sel.value })
                });
                toast('บันทึกเขตเวลาแล้ว');
            } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
        });
    }

    // ---------- Local storage info ----------
    function initLocalStorage() {
        const info = $('#localStorageInfo');
        function refresh() {
            if (!info) return;
            let items = 0, bytes = 0;
            for (let i = 0; i < localStorage.length; i++) {
                const k = localStorage.key(i);
                items++;
                bytes += (k.length + (localStorage.getItem(k) || '').length) * 2;
            }
            info.textContent = `มี ${items} รายการ · ขนาดประมาณ ${(bytes / 1024).toFixed(1)} KB`;
        }
        refresh();
        $('#btnClearLocal')?.addEventListener('click', () => {
            const doClear = () => {
                localStorage.clear();
                refresh();
                toast('ล้างข้อมูลในเบราว์เซอร์แล้ว');
            };
            confirmAction('ล้างข้อมูลในเบราว์เซอร์?', 'ล้าง', 'ล้างข้อมูล').then(ok => { if (ok) doClear(); });
        });
    }

    // ---------- Import data ----------
    function initImportData() {
        const btn = $('#btnImportData');
        const fileInp = $('#importFile');
        if (!btn || !fileInp) return;

        fileInp.addEventListener('change', () => {
            const labelText = $('#importFileNameText');
            if (fileInp.files.length) {
                const file = fileInp.files[0];
                let sizeStr = '';
                if (file.size < 1024) {
                    sizeStr = file.size + ' B';
                } else if (file.size < 1024 * 1024) {
                    sizeStr = (file.size / 1024).toFixed(1) + ' KB';
                } else {
                    sizeStr = (file.size / (1024 * 1024)).toFixed(1) + ' MB';
                }
                if (labelText) {
                    labelText.textContent = `${file.name} (${sizeStr})`;
                }
                btn.disabled = false;
            } else {
                if (labelText) {
                    labelText.textContent = 'คลิกเพื่อเลือกไฟล์ข้อมูลสำรอง (.json)';
                }
                btn.disabled = true;
            }
        });

        btn.addEventListener('click', async () => {
            if (!fileInp.files.length) {
                toast('กรุณาเลือกไฟล์ JSON ที่ต้องการนำเข้า', 'danger');
                return;
            }

            const file = fileInp.files[0];
            const proceed = async () => {
                const fd = new FormData();
                fd.append('file', file);
                fd.append('_csrf', document.querySelector('meta[name="csrf-token"]')?.content ?? '');

                btn.disabled = true;
                toast('กำลังนำเข้าข้อมูล...');

                try {
                    const res = await fetch(BASE_URL + '/api/settings/import', {
                        method: 'POST',
                        headers: { 'X-CSRF-Token': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                        body: fd
                    });

                    if (!res.ok) {
                        let msg = 'นำเข้าข้อมูลไม่สำเร็จ';
                        try { const j = await res.json(); msg = j.error || msg; } catch (_) {}
                        throw new Error(msg);
                    }

                    const result = await res.json().catch(() => ({}));
                    const summary = Number.isFinite(result.total)
                        ? `นำเข้าข้อมูลสำเร็จ ${result.total.toLocaleString('th-TH')} รายการ`
                        : 'นำเข้าข้อมูลสำเร็จแล้ว';

                    if (window.Swal) {
                        Swal.fire({
                            icon: 'success',
                            title: summary,
                            text: 'ระบบได้รีสโตร์ข้อมูลสำรองเรียบร้อยแล้ว กำลังโหลดหน้าใหม่...',
                            timer: 2000,
                            showConfirmButton: false
                        });
                        setTimeout(() => { window.location.reload(); }, 2000);
                    } else {
                        toast(summary);
                        setTimeout(() => { window.location.reload(); }, 1500);
                    }
                } catch (err) {
                    toast(err.message || 'นำเข้าไม่สำเร็จ', 'danger');
                    btn.disabled = false;
                }
            };

            confirmAction(
                'ข้อมูลทั้งหมดในบัญชีปัจจุบันของคุณจะถูกลบและเขียนทับด้วยข้อมูลในไฟล์สำรอง การดำเนินการนี้ไม่สามารถยกเลิกได้',
                'ยืนยันนำเข้าข้อมูล',
                'คำเตือนเรื่องข้อมูลสูญหาย'
            ).then(ok => { if (ok) proceed(); });
        });
    }

    // ---------- Danger: delete account ----------
    function initDangerZone() {
        const confirmInp = $('#delConfirm');
        const passInp    = $('#delPassword');
        const btn        = $('#btnDeleteAccount');
        if (!btn) return;

        function updateBtn() {
            btn.disabled = !(confirmInp.value === 'DELETE' && passInp.value.length >= 1);
        }
        confirmInp.addEventListener('input', updateBtn);
        passInp.addEventListener('input', updateBtn);

        btn.addEventListener('click', async () => {
            const proceed = async () => {
                try {
                    await apiFetch(BASE_URL + '/api/settings/delete', {
                        method: 'POST',
                        body: JSON.stringify({ password: passInp.value, confirm_text: confirmInp.value })
                    });
                    window.location.href = BASE_URL + '/login';
                } catch (err) {
                    toast(err.message || 'ลบบัญชีไม่สำเร็จ', 'danger');
                }
            };
            confirmAction('ข้อมูลทั้งหมดจะถูกลบถาวรและไม่สามารถกู้คืนได้', 'ลบถาวร', 'ยืนยันลบบัญชี?').then(ok => { if (ok) proceed(); });
        });
    }


    // ---------- Init ----------
    document.addEventListener('DOMContentLoaded', () => {
        initTabs();
        $('#btnSaveProfile')?.addEventListener('click', saveProfile);
        $$('input[name="theme"]').forEach(r => r.addEventListener('change', () => setTheme(r.value)));
        $('#dayColorSwitch')?.addEventListener('change', e => setDayColor(e.target));
        initPasswordForm();
        initTimezone();
        initLocalStorage();
        initImportData();
        initDangerZone();
        initDevices();
    });
})();
