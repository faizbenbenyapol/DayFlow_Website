// =====================================================
// settings.js — Settings page
// =====================================================
(function () {
    'use strict';
    const $ = (s, c) => (c || document).querySelector(s);
    const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

    // ---------- Tabs ----------
    // Other settings-*.js files load their own pane the first time it is shown:
    //   onSettingsTab('shares', loadShares)
    // The callback also runs at once if that pane is already open.
    const tabCallbacks = {};
    const tabRan = new WeakSet();
    let activeTab = null;

    window.onSettingsTab = function (name, callback) {
        (tabCallbacks[name] = tabCallbacks[name] || []).push(callback);
        if (activeTab === name) { tabRan.add(callback); callback(); }
    };

    function showTab(name) {
        activeTab = name;
        (tabCallbacks[name] || []).forEach(cb => {
            if (!tabRan.has(cb)) { tabRan.add(cb); cb(); }
        });
    }

    function initTabs() {
        const tabs = $$('.settings-tab');
        const panes = $$('.settings-pane');

        const select = (btn, focus) => {
            const name = btn.dataset.tab;
            tabs.forEach(t => {
                t.setAttribute('aria-selected', String(t === btn));
                t.tabIndex = t === btn ? 0 : -1;
            });
            panes.forEach(p => { p.hidden = p.id !== 'tab-' + name; });
            try { localStorage.setItem('settings_last_tab', name); } catch (_) { /* not remembered */ }
            if (focus) btn.focus();
            // On a phone the topics scroll sideways; keep the open one in view.
            btn.scrollIntoView({ block: 'nearest', inline: 'center' });
            showTab(name);
        };

        tabs.forEach((btn, i) => {
            btn.addEventListener('click', () => select(btn));
            btn.addEventListener('keydown', e => {
                const step = (e.key === 'ArrowDown' || e.key === 'ArrowRight') ? 1 : (e.key === 'ArrowUp' || e.key === 'ArrowLeft') ? -1 : 0;
                if (!step) return;
                e.preventDefault();
                select(tabs[(i + step + tabs.length) % tabs.length], true);
            });
        });

        // The topic last open, unless the address names one (#shares, #menus ...).
        let start = tabs[0];
        try {
            const last = localStorage.getItem('settings_last_tab');
            const fromHash = location.hash.replace('#', '');
            start = tabs.find(t => t.dataset.tab === fromHash) || tabs.find(t => t.dataset.tab === last) || tabs[0];
        } catch (_) { /* first topic */ }
        // After the other scripts have registered what they want to load.
        setTimeout(() => select(start), 0);
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
                list.innerHTML = '<div class="empty-state"><p class="empty-state-title">ยังไม่มีอุปกรณ์ที่จำไว้</p><p class="empty-state-text">ครั้งถัดไปที่เข้าสู่ระบบ ให้เลือก "จดจำอุปกรณ์นี้"</p></div>';
                return;
            }
            list.innerHTML = devices.map(device => `
                <div class="device-row ${device.is_current ? 'is-current' : ''}">
                    <div class="device-main">
                        <div class="device-title">${escHtml(deviceName(device.user_agent))} <span class="device-browser">${escHtml(browserName(device.user_agent))}</span>${device.is_current ? '<span class="device-current">อุปกรณ์นี้</span>' : ''}</div>
                        <div class="device-meta">IP ${escHtml(device.ip_address || 'ไม่ทราบ')} · ใช้งานล่าสุด ${escHtml(formatDate(device.last_used_at || device.created_at))}</div>
                        <div class="device-meta">หมดอายุ ${escHtml(formatDate(device.expires_at))}</div>
                    </div>
                    <button class="btn btn-sm device-revoke" type="button" data-device-id="${Number(device.id)}">ยกเลิก</button>
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
            list.innerHTML = '<div class="skel-row"><span class="skel skel-w-60"></span></div>';
            try {
                const data = await apiFetch(BASE_URL + '/api/settings/devices');
                render(Array.isArray(data.devices) ? data.devices : []);
                loaded = true;
            } catch (err) {
                list.innerHTML = '<div class="alert alert-danger" role="alert">โหลดรายการไม่สำเร็จ <button class="btn btn-sm" id="btnRetryDevices" type="button">ลองอีกครั้ง</button></div>';
                $('#btnRetryDevices')?.addEventListener('click', load);
            }
        };
        onSettingsTab('devices', () => { if (!loaded) load(); });
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
            fill.style.setProperty('--v', (newPw.value ? Math.max(score, 1) / 5 * 100 : 0) + '%');
            $('#pwStrength').dataset.level = !newPw.value ? '' : score <= 1 ? 'weak' : score <= 3 ? 'fair' : 'good';
            txt.textContent = newPw.value ? `ระดับ: ${label}` : 'อย่างน้อย 8 ตัวอักษร';
        });

        // Match hint
        const confirm = $('#pwConfirm'), hint = $('#pwMatchHint');
        const updateMatch = () => {
            hint.classList.remove('ok', 'bad');
            if (!confirm.value) { hint.textContent = ''; return; }
            const same = confirm.value === newPw.value;
            hint.textContent = same ? 'รหัสผ่านตรงกัน' : 'รหัสผ่านไม่ตรงกัน';
            hint.classList.add(same ? 'ok' : 'bad');
        };
        confirm?.addEventListener('input', updateMatch);
        newPw?.addEventListener('input', updateMatch);

        // Submit
        $('#btnChangePassword')?.addEventListener('click', async () => {
            const current = $('#pwCurrent').value;
            const nw = newPw.value;
            const cf = confirm.value;
            const problem = !current || !nw || !cf ? 'กรอกให้ครบทั้งสามช่อง'
                : nw.length < 8 ? 'รหัสผ่านใหม่ต้องมีอย่างน้อย 8 ตัวอักษร'
                : nw !== cf ? 'รหัสผ่านใหม่กับช่องยืนยันไม่ตรงกัน'
                : nw === current ? 'รหัสผ่านใหม่ต้องต่างจากรหัสผ่านปัจจุบัน' : '';
            if (problem) { toast(problem, 'danger'); return; }

            try {
                await apiFetch(BASE_URL + '/api/settings/password', {
                    method: 'POST',
                    body: JSON.stringify({ current_password: current, new_password: nw, confirm_password: cf })
                });
                $('#pwCurrent').value = ''; newPw.value = ''; confirm.value = '';
                fill.style.setProperty('--v', '0%'); $('#pwStrength').dataset.level = ''; txt.textContent = 'อย่างน้อย 8 ตัวอักษร';
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
                    labelText.textContent = 'กดเพื่อเลือกไฟล์ข้อมูลสำรอง (.json)';
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
