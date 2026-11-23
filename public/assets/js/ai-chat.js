/* =====================================================
   ai-chat.js — แท็บ "สั่งงาน" ของหน้าผู้ช่วย AI
   บทสนทนาเก็บในหน่วยความจำของหน้าเท่านั้น ไม่เก็บลง localStorage
   และไม่เคยใส่ข้อความจาก AI/ผู้ใช้ด้วย innerHTML
===================================================== */
(function () {
    'use strict';

    const pane = document.getElementById('ai-pane-chat');
    if (!pane) return;

    const log = document.getElementById('aiChatLog');
    const input = document.getElementById('aiChatInput');
    const sendBtn = document.getElementById('btnChatSend');
    const newBtn = document.getElementById('btnChatNew');
    const providerSel = document.getElementById('aiChatProvider');
    const STORAGE_KEY = 'ai_chat_provider';
    const EXAMPLES = [
        'เพิ่มงานโทรหาแม่พรุ่งนี้',
        'วันนี้มีงานอะไรบ้าง',
        'จดรายจ่ายค่ากาแฟ 65 บาท',
        'ติ๊กนิสัยดื่มน้ำวันนี้',
    ];

    /** บทสนทนาของหน้านี้ รูปแบบ {role: 'user'|'assistant', content: string} */
    let conversation = [];
    /** true ระหว่างรอ API เพื่อกันการส่งซ้ำ */
    let busy = false;

    /** เลื่อนรายการข้อความลงล่างสุด (ผลข้างเคียง: เปลี่ยน scrollTop) */
    function scrollToEnd() {
        log.scrollTop = log.scrollHeight;
    }

    /**
     * เพิ่มข้อความหนึ่งแถวในรายการ
     * รับ kind = user|assistant|error|note และ text; คืน element ของแถว
     */
    function addRow(kind, text) {
        const row = document.createElement('div');
        row.className = 'ai-chat-msg' + (kind === 'user' ? ' ai-chat-msg-own' : '');
        const bubble = document.createElement('div');
        bubble.className = 'ai-chat-bubble';
        if (kind === 'error') bubble.classList.add('ai-bad');
        if (kind === 'note') bubble.classList.add('ai-note');
        bubble.textContent = text;
        row.appendChild(bubble);
        log.appendChild(row);
        scrollToEnd();
        return row;
    }

    /** เปลี่ยนข้อความในแถว (ใช้ textContent) รับ row และข้อความใหม่ */
    function setRowText(row, text) {
        const bubble = row.querySelector('.ai-chat-bubble');
        if (bubble) bubble.textContent = text;
    }

    /** วาดสถานะยังไม่มีข้อความ พร้อมตัวอย่างคำสั่งที่กดแล้วใส่ช่องพิมพ์ (ไม่ส่ง) */
    function renderEmpty() {
        log.textContent = '';
        const box = document.createElement('div');
        box.className = 'ai-chat-empty';

        const hint = document.createElement('p');
        hint.className = 'ai-note';
        hint.textContent = 'พิมพ์สั่งได้เลย เช่น เพิ่มหรือติ๊กงาน ดูงานวันนี้ ติ๊กนิสัย หรือจดรายรับรายจ่าย';
        box.appendChild(hint);

        const chips = document.createElement('div');
        chips.className = 'ai-preset-chips';
        EXAMPLES.forEach(word => {
            const chip = document.createElement('button');
            chip.type = 'button';
            chip.className = 'ai-chip';
            chip.textContent = word;
            chip.addEventListener('click', () => {
                input.value = word;
                input.focus();
            });
            chips.appendChild(chip);
        });
        box.appendChild(chips);
        log.appendChild(box);
    }

    /** เปิด/ปิดช่องพิมพ์และปุ่มระหว่างรอคำตอบ รับ on = true เมื่อกำลังรอ */
    function setBusy(on) {
        busy = on;
        input.disabled = on;
        sendBtn.disabled = on;
        newBtn.disabled = on;
        log.setAttribute('aria-busy', String(on));
    }

    /**
     * แสดงแถวคำสั่งที่รอผู้ใช้ยืนยัน (pending.text) พร้อมปุ่มยืนยัน/ไม่ทำ
     * ทั้งสองทางบันทึกผลเป็นข้อความ assistant ในบทสนทนา
     */
    function showPending(pending) {
        const row = addRow('assistant', String(pending.text || 'ต้องยืนยันก่อนทำรายการนี้'));
        const actions = document.createElement('div');
        actions.className = 'ai-chat-actions';

        const yes = document.createElement('button');
        yes.type = 'button';
        yes.className = 'btn btn-danger btn-sm';
        yes.textContent = 'ยืนยัน';

        const no = document.createElement('button');
        no.type = 'button';
        no.className = 'btn btn-ghost btn-sm';
        no.textContent = 'ไม่ทำ';

        /** ปิดปุ่มแล้วแทนที่แถวด้วยผลของคำสั่ง */
        const finish = reply => {
            actions.remove();
            setRowText(row, reply);
            conversation.push({ role: 'assistant', content: reply });
            scrollToEnd();
        };

        yes.addEventListener('click', async () => {
            yes.disabled = true;
            no.disabled = true;
            try {
                const data = await apiFetch(BASE_URL + '/api/ai/chat/confirm', {
                    method: 'POST',
                    body: JSON.stringify({ command: pending.command, args: pending.args || {} }),
                });
                finish(String(data.reply || 'ทำแล้ว'));
            } catch (e) {
                yes.disabled = false;
                no.disabled = false;
                const oldError = row.querySelector('.ai-bad');
                if (oldError) oldError.remove();
                const err = document.createElement('div');
                err.className = 'ai-bad';
                err.textContent = e.message;
                row.appendChild(err);
                scrollToEnd();
            }
        });

        no.addEventListener('click', () => finish('ไม่ได้ทำอะไร'));

        actions.appendChild(yes);
        actions.appendChild(no);
        row.appendChild(actions);
        scrollToEnd();
    }

    /**
     * ส่งบทสนทนาปัจจุบันไปที่ /api/ai/chat แล้วแสดงข้อความตอบหรือ pending
     * ผลข้างเคียง: เพิ่ม/ลบแถวใน log และต่อบทสนทนาด้วยคำตอบของ AI
     */
    async function requestReply() {
        setBusy(true);
        const thinking = addRow('note', 'กำลังคิด…');
        try {
            const data = await apiFetch(BASE_URL + '/api/ai/chat', {
                method: 'POST',
                body: JSON.stringify({
                    provider: providerSel.value,
                    messages: conversation.slice(-20),
                }),
            });
            thinking.remove();
            if (data.pending) {
                showPending(data.pending);
            } else {
                const reply = String(data.reply || '');
                addRow('assistant', reply);
                conversation.push({ role: 'assistant', content: reply });
            }
        } catch (e) {
            thinking.remove();
            const row = addRow('error', e.message);
            const actions = document.createElement('div');
            actions.className = 'ai-chat-actions';
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn btn-ghost btn-sm';
            retry.textContent = 'ส่งอีกครั้ง';
            retry.addEventListener('click', () => {
                row.remove();
                requestReply();
            });
            actions.appendChild(retry);
            row.appendChild(actions);
            scrollToEnd();
        } finally {
            setBusy(false);
        }
    }

    /** ส่งข้อความที่พิมพ์อยู่: ต้องไม่ว่าง เพิ่มลงบทสนทนาแล้วเรียก API */
    function sendMessage() {
        if (busy) return;
        const text = input.value.trim();
        if (text === '') return;
        input.value = '';
        conversation.push({ role: 'user', content: text });
        addRow('user', text);
        requestReply();
    }

    /** ล้างบทสนทนาในหน่วยความจำและวาดสถานะว่างใหม่ */
    function resetChat() {
        conversation = [];
        renderEmpty();
        input.value = '';
        input.focus();
    }

    /** จำผู้ให้บริการที่เลือกไว้ (ข้ามเมื่อเบราว์เซอร์ไม่ให้ใช้ localStorage) */
    function rememberProvider() {
        try {
            localStorage.setItem(STORAGE_KEY, providerSel.value);
        } catch (_) {
            /* ไม่จำ */
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        renderEmpty();
        try {
            const saved = localStorage.getItem(STORAGE_KEY);
            const exists = Array.from(providerSel.options).some(o => o.value === saved);
            if (saved && exists) providerSel.value = saved;
        } catch (_) {
            /* ใช้ตัวเลือกแรก */
        }

        providerSel.addEventListener('change', rememberProvider);
        sendBtn.addEventListener('click', sendMessage);
        newBtn.addEventListener('click', resetChat);
        input.addEventListener('keydown', e => {
            if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) {
                e.preventDefault();
                sendMessage();
            }
        });
    });
})();
