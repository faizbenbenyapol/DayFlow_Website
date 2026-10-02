// =====================================================
// settings-telegram.js — Settings: Telegram
// Loaded after settings.js; classic scripts, so $ and $$ are local here.
// =====================================================
(function () {
    'use strict';
    const $ = (s, c) => (c || document).querySelector(s);
    const $$ = (s, c) => Array.from((c || document).querySelectorAll(s));

    // ---------- Telegram ----------
    function initTelegram() {
        $('#btnSaveTelegram')?.addEventListener('click', async () => {
            const token = $('#telegramBotToken')?.value.trim();
            const chatId = $('#telegramChatId')?.value.trim();
            const events = {};
            $$('input[name="tg_events[]"]').forEach(cb => {
                events[cb.value] = cb.checked;
            });
            
            try {
                await apiFetch(BASE_URL + '/api/settings/telegram', {
                    method: 'POST',
                    body: JSON.stringify({ telegram_bot_token: token, telegram_chat_id: chatId, telegram_notify_events: events })
                });
                if (window.Swal) {
                    Swal.fire({ icon: 'success', title: 'บันทึกการตั้งค่า Telegram แล้ว', timer: 1500, showConfirmButton: false });
                } else {
                    toast('บันทึกการตั้งค่า Telegram แล้ว');
                }
            } catch (err) { toast(err.message || 'บันทึกไม่สำเร็จ', 'danger'); }
        });

        $('#btnTestTelegram')?.addEventListener('click', async () => {
            const token = $('#telegramBotToken')?.value.trim();
            const chatId = $('#telegramChatId')?.value.trim();
            if (!token || !chatId) {
                toast('กรุณากรอก Bot Token และ Chat ID ก่อนทดสอบ', 'danger');
                return;
            }
            try {
                const btn = $('#btnTestTelegram');
                if (btn) btn.disabled = true;
                await apiFetch(BASE_URL + '/api/settings/telegram/test', {
                    method: 'POST',
                    body: JSON.stringify({ telegram_bot_token: token, telegram_chat_id: chatId })
                });
                if (btn) btn.disabled = false;
                if (window.Swal) {
                    Swal.fire({ icon: 'success', title: 'ส่งข้อความสำเร็จ', text: 'ลองเช็คใน Telegram ของคุณดูครับ', confirmButtonColor: '#e05c4b' });
                } else {
                    toast('ส่งข้อความทดสอบสำเร็จ ลองเช็คใน Telegram ของคุณดูครับ');
                }
            } catch (err) { 
                const btn = $('#btnTestTelegram');
                if (btn) btn.disabled = false;
                toast(err.message || 'ทดสอบไม่สำเร็จ', 'danger'); 
            }
        });
        $('#btnTestCron')?.addEventListener('click', async () => {
            try {
                const btn = $('#btnTestCron');
                if (btn) btn.disabled = true;
                const res = await fetch(BASE_URL + '/api/settings/telegram/cron', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '' }
                });
                const data = await res.json();
                if (btn) btn.disabled = false;
                
                if (data.ok) {
                    if (window.Swal) {
                        Swal.fire({ icon: 'success', title: 'รัน Cron สำเร็จ', text: data.output || 'ไม่พบรายการที่ต้องส่งแจ้งเตือนในขณะนี้', confirmButtonColor: '#e05c4b' });
                    } else {
                        alert('รัน Cron สำเร็จ!\n\n' + (data.output || 'ไม่มีรายการใหม่'));
                    }
                } else {
                    toast(data.error || 'เกิดข้อผิดพลาดในการรัน Cron', 'danger');
                }
            } catch (err) {
                const btn = $('#btnTestCron');
                if (btn) btn.disabled = false;
                toast('ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้', 'danger');
            }
        });
    }


    document.addEventListener('DOMContentLoaded', () => {
        initTelegram();
    });
})();
