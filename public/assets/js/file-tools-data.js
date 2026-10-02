/* =====================================================
   file-tools-data.js
   Data conversion tools (client-side)
   Loaded after file-tools.js, which provides $, setStatus,
   apiPost, wireDropZone and the PDF helpers.
   ===================================================== */

'use strict';

// ═══════════════════════════════════════════════════════
// DATA CONVERSION  (client-side)
// ═══════════════════════════════════════════════════════

// ── JSON ↔ CSV ─────────────────────────────────────────
(function () {
    let jcDir = 'json2csv';
    const jcCard = $('jcInput')?.closest('.card-body');
    const jcDirBtns = jcCard ? Array.from(jcCard.querySelectorAll('.ft-dir-btn')) : [];
    jcDirBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            jcDirBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            jcDir = btn.dataset.dir;
            $('jcInput').placeholder = jcDir === 'json2csv' ? '[{"name":"Alice","age":25}]' : 'name,age\nAlice,25';
        });
    });

    $('btnJC').addEventListener('click', () => {
        const input = $('jcInput').value.trim();
        if (!input) return;
        try {
            let out;
            if (jcDir === 'json2csv') {
                const arr = JSON.parse(input);
                const rows = Array.isArray(arr) ? arr : [arr];
                const keys = Object.keys(rows[0]);
                out = [keys.join(','), ...rows.map(r => keys.map(k => JSON.stringify(r[k] ?? '')).join(','))].join('\n');
            } else {
                const lines = input.split('\n').filter(Boolean);
                const headers = lines[0].split(',').map(h => h.trim().replace(/^"|"$/g, ''));
                const result = lines.slice(1).map(line => {
                    const vals = line.match(/(".*?"|[^,]+)/g) || [];
                    const obj = {};
                    headers.forEach((h, i) => { obj[h] = (vals[i] || '').replace(/^"|"$/g, ''); });
                    return obj;
                });
                out = JSON.stringify(result, null, 2);
            }
            $('jcOutput').value = out;
            setStatus('jcStatus', 'แปลงสำเร็จ', 'ok');
        } catch (e) {
            setStatus('jcStatus', 'แปลงไม่สำเร็จ: ' + e.message, 'err');
        }
    });
    $('btnJCCopy').addEventListener('click', () => copyToClipboard($('jcOutput').value));
    $('btnJCDownload').addEventListener('click', () => {
        const v = $('jcOutput').value; if (!v) return;
        const ext = jcDir === 'json2csv' ? 'csv' : 'json';
        triggerDownload(new Blob([v], { type: 'text/plain' }), 'converted.' + ext);
    });
})();

// ── JSON ↔ XML ─────────────────────────────────────────
(function () {
    let jxDir = 'json2xml';
    const jxDirBtns = Array.from(document.querySelectorAll('.ft-dir-btn')).filter(b => b.closest('.card-body') === $('jxInput')?.closest('.card-body'));
    jxDirBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            jxDirBtns.forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            jxDir = btn.dataset.dir;
        });
    });

    function jsonToXml(obj, tag = 'root') {
        if (typeof obj !== 'object' || obj === null) return `<${tag}>${String(obj)}</${tag}>`;
        if (Array.isArray(obj)) return obj.map(item => jsonToXml(item, 'item')).join('\n');
        const inner = Object.entries(obj).map(([k, v]) => jsonToXml(v, k)).join('\n');
        return `<${tag}>\n${inner}\n</${tag}>`;
    }

    function xmlToJson(xml) {
        const parser = new DOMParser();
        const doc = parser.parseFromString(xml, 'text/xml');
        function nodeToObj(node) {
            if (node.nodeType === 3) return node.nodeValue.trim();
            const obj = {};
            Array.from(node.childNodes).forEach(child => {
                if (child.nodeType !== 1) return;
                const val = nodeToObj(child);
                if (obj[child.nodeName] !== undefined) {
                    if (!Array.isArray(obj[child.nodeName])) obj[child.nodeName] = [obj[child.nodeName]];
                    obj[child.nodeName].push(val);
                } else { obj[child.nodeName] = val; }
            });
            return obj;
        }
        return nodeToObj(doc.documentElement);
    }

    $('btnJX').addEventListener('click', () => {
        const input = $('jxInput').value.trim();
        if (!input) return;
        try {
            let out;
            if (jxDir === 'json2xml') {
                const obj = JSON.parse(input);
                out = '<?xml version="1.0" encoding="UTF-8"?>\n' + jsonToXml(obj);
            } else {
                const obj = xmlToJson(input);
                out = JSON.stringify(obj, null, 2);
            }
            $('jxOutput').value = out;
            setStatus('jxStatus', 'แปลงสำเร็จ', 'ok');
        } catch (e) {
            setStatus('jxStatus', 'แปลงไม่สำเร็จ: ' + e.message, 'err');
        }
    });
    $('btnJXCopy').addEventListener('click', () => copyToClipboard($('jxOutput').value));
    $('btnJXDownload').addEventListener('click', () => {
        const v = $('jxOutput').value; if (!v) return;
        const ext = jxDir === 'json2xml' ? 'xml' : 'json';
        triggerDownload(new Blob([v], { type: 'text/plain' }), 'converted.' + ext);
    });
})();

// ── JSON Prettify / Minify ─────────────────────────────
(function () {
    $('btnJsonPretty').addEventListener('click', () => {
        try {
            $('jsonFmtOutput').value = JSON.stringify(JSON.parse($('jsonFmtInput').value), null, 2);
        } catch (e) { $('jsonFmtOutput').value = 'JSON ไม่ถูกต้อง: ' + e.message; }
    });
    $('btnJsonMinify').addEventListener('click', () => {
        try {
            $('jsonFmtOutput').value = JSON.stringify(JSON.parse($('jsonFmtInput').value));
        } catch (e) { $('jsonFmtOutput').value = 'JSON ไม่ถูกต้อง: ' + e.message; }
    });
    $('btnJsonFmtCopy').addEventListener('click', () => copyToClipboard($('jsonFmtOutput').value));
})();

// ── Base64 ─────────────────────────────────────────────
(function () {
    let b64Mode = 'text';
    let b64FileData = null;

    document.querySelectorAll('.ft-b64-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.ft-b64-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            b64Mode = btn.dataset.b64;
            $('b64TextSection').hidden = b64Mode !== 'text';
            $('b64FileSection').hidden = b64Mode !== 'file';
        });
    });

    $('btnB64Enc').addEventListener('click', () => {
        $('b64Output').value = btoa(unescape(encodeURIComponent($('b64Input').value)));
    });
    $('btnB64Dec').addEventListener('click', () => {
        try { $('b64Output').value = decodeURIComponent(escape(atob($('b64Input').value))); }
        catch (e) { $('b64Output').value = 'ถอดรหัสไม่สำเร็จ'; }
    });
    $('btnB64Copy').addEventListener('click', () => copyToClipboard($('b64Output').value));

    wireDropZone('b64FileDrop', 'b64FileInput', (files) => {
        const f = files[0];
        const fr = new FileReader();
        fr.onload = () => {
            b64FileData = { name: f.name, type: f.type, data: fr.result.split(',')[1] };
            $('b64FileOutput').value = b64FileData.data;
        };
        fr.readAsDataURL(f);
    });

    $('btnB64FileCopy').addEventListener('click', () => copyToClipboard($('b64FileOutput').value));
    $('btnB64FileDownload').addEventListener('click', () => {
        if (!b64FileData) return;
        const bytes = Uint8Array.from(atob(b64FileData.data), c => c.charCodeAt(0));
        triggerDownload(new Blob([bytes], { type: b64FileData.type }), b64FileData.name);
    });
})();

// ── Hash ───────────────────────────────────────────────
(function () {
    let hashSrc = 'text';
    let hashFile = null;

    document.querySelectorAll('.ft-hash-tab').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.ft-hash-tab').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            hashSrc = btn.dataset.hsrc;
            $('hashTextSection').hidden = hashSrc !== 'text';
            $('hashFileSection').hidden = hashSrc !== 'file';
        });
    });

    wireDropZone('hashFileDrop', 'hashFileInput', (files) => {
        hashFile = files[0];
        $('hashFileInfo').textContent = hashFile.name + ' (' + fmtBytes(hashFile.size) + ')';
    });

    $('btnHash').addEventListener('click', async () => {
        let buffer;
        if (hashSrc === 'text') {
            const text = $('hashInput').value;
            buffer = new TextEncoder().encode(text).buffer;
        } else {
            if (!hashFile) return;
            buffer = await readFileAsArrayBuffer(hashFile);
        }

        const results = $('hashResults');
        results.innerHTML = '<div class="ft-status info">กำลังคำนวณ…</div>';

        async function digest(algo) {
            const ab = await crypto.subtle.digest(algo, buffer);
            return Array.from(new Uint8Array(ab)).map(b => b.toString(16).padStart(2, '0')).join('');
        }

        const [sha256, sha1] = await Promise.all([digest('SHA-256'), digest('SHA-1')]);
        // MD5 via SparkMD5
        const md5 = window.SparkMD5
            ? SparkMD5.ArrayBuffer.hash(buffer)
            : '(ต้องการ SparkMD5 CDN)';

        results.innerHTML = '';
        [['SHA-256', sha256], ['SHA-1', sha1], ['MD5', md5]].forEach(([algo, hash]) => {
            const row = document.createElement('div');
            row.className = 'ft-hash-row';
            row.innerHTML = `<label>${algo}</label><input type="text" readonly value="${hash}">`;
            row.querySelector('input').addEventListener('click', e => { e.target.select(); copyToClipboard(hash); });
            results.appendChild(row);
        });
    });
})();

// ── URL Encode / Decode ────────────────────────────────
(function () {
    $('btnUrlEnc').addEventListener('click', () => { $('urlOutput').value = encodeURIComponent($('urlInput').value); });
    $('btnUrlDec').addEventListener('click', () => {
        try { $('urlOutput').value = decodeURIComponent($('urlInput').value); }
        catch (e) { $('urlOutput').value = 'ถอดรหัสไม่สำเร็จ: ' + e.message; }
    });
    $('btnUrlCopy').addEventListener('click', () => copyToClipboard($('urlOutput').value));
})();

