/* =====================================================
   focus.js — the Pomodoro timer

   The countdown is worked out from the moment it is due to end, not by
   subtracting one second per tick. A tab in the background, or a phone with its
   screen off, runs timers slowly or not at all; the clock on the wall does not.
   A running timer survives a reload: its end time is kept in localStorage.
===================================================== */

document.addEventListener('DOMContentLoaded', function () {
    const gridLayout = document.querySelector('.focus-layout-grid');
    const timerDisplay = document.getElementById('timerDisplay');
    const timerPhaseLabel = document.getElementById('timerPhaseLabel');
    const timerProgress = document.getElementById('timerProgress');

    const btnStartStop = document.getElementById('btnStartStop');
    const btnResetTimer = document.getElementById('btnResetTimer');
    const btnSkipTimer = document.getElementById('btnSkipTimer');

    const inputWorkDuration = document.getElementById('inputWorkDuration');
    const inputShortBreak = document.getElementById('inputShortBreak');
    const inputLongBreak = document.getElementById('inputLongBreak');

    const selectFocusTask = document.getElementById('selectFocusTask');
    const inputFocusTitle = document.getElementById('inputFocusTitle');
    const logsEl = document.getElementById('focusLogs');

    const STORE = 'dayflow.focus';
    const PAGE_TITLE = document.title;
    const PHASE_LABEL = { work: 'กำลังโฟกัสงาน', short_break: 'พักสั้น', long_break: 'พักยาว' };
    const TYPE_LABEL = { work: 'โฟกัสงาน', short_break: 'พักสั้น', long_break: 'พักยาว' };

    // State
    let ticker = null;
    let endsAt = null;              // epoch ms the running timer is due, null when stopped
    let secondsRemaining = 25 * 60; // what the face shows when stopped
    let totalDurationSeconds = 25 * 60;
    let currentMode = 'work';       // 'work', 'short_break', 'long_break'

    const isRunning = () => endsAt !== null;

    fetchLogs();

    document.querySelectorAll('.focus-mode-btn').forEach(btn => {
        btn.addEventListener('click', () => switchMode(btn.dataset.mode));
    });
    btnStartStop.addEventListener('click', () => (isRunning() ? pauseTimer() : startTimer()));
    btnResetTimer.addEventListener('click', () => switchMode(currentMode));
    btnSkipTimer.addEventListener('click', skipTimer);

    // A new length takes effect at once, unless a timer is in the middle of running.
    [inputWorkDuration, inputShortBreak, inputLongBreak].forEach(input => {
        input.addEventListener('change', () => { if (!isRunning()) switchMode(currentMode, false); });
    });

    // Back from another tab: the interval may have been starved, so look at the clock.
    document.addEventListener('visibilitychange', () => { if (!document.hidden && isRunning()) tick(); });

    function getModeDuration(mode) {
        const minutes = parseInt({ work: inputWorkDuration, short_break: inputShortBreak, long_break: inputLongBreak }[mode].value, 10);
        return (Number.isFinite(minutes) && minutes > 0 ? minutes : 25) * 60;
    }

    function switchMode(mode, stopCurrent = true) {
        if (stopCurrent) pauseTimer(false);

        currentMode = mode;
        gridLayout?.setAttribute('data-mode', mode);

        document.querySelectorAll('.focus-mode-btn').forEach(btn => {
            const on = btn.dataset.mode === mode;
            btn.classList.toggle('active', on);
            btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        });

        totalDurationSeconds = secondsRemaining = getModeDuration(mode);
        btnStartStop.textContent = 'เริ่มจับเวลา';
        timerPhaseLabel.textContent = PHASE_LABEL[mode];
        updateDisplay();
    }

    function clock(seconds) {
        return String(Math.floor(seconds / 60)).padStart(2, '0') + ':' + String(seconds % 60).padStart(2, '0');
    }

    function updateDisplay() {
        timerDisplay.textContent = clock(secondsRemaining);
        timerProgress.style.width = Math.round((1 - secondsRemaining / totalDurationSeconds) * 100) + '%';
        // The countdown also shows in the browser tab, so it can be read from another one.
        document.title = isRunning() ? clock(secondsRemaining) + ' · ' + PHASE_LABEL[currentMode] : PAGE_TITLE;
    }

    function tick() {
        secondsRemaining = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
        updateDisplay();
        if (secondsRemaining === 0) handleTimerFinished();
    }

    function startTimer() {
        endsAt = Date.now() + secondsRemaining * 1000;
        remember();
        btnStartStop.textContent = 'หยุดชั่วคราว';
        clearInterval(ticker);
        ticker = setInterval(tick, 250);
        tick();
    }

    function pauseTimer(keepRemaining = true) {
        if (isRunning() && keepRemaining) {
            secondsRemaining = Math.max(0, Math.round((endsAt - Date.now()) / 1000));
        }
        endsAt = null;
        clearInterval(ticker);
        ticker = null;
        forget();
        btnStartStop.textContent = secondsRemaining < totalDurationSeconds && secondsRemaining > 0 ? 'จับเวลาต่อ' : 'เริ่มจับเวลา';
        updateDisplay();
    }

    /* ---------- survive a reload ---------- */
    function remember() {
        try { localStorage.setItem(STORE, JSON.stringify({ mode: currentMode, endsAt, total: totalDurationSeconds })); } catch { /* private mode: it still runs */ }
    }

    function forget() {
        try { localStorage.removeItem(STORE); } catch { /* nothing to forget */ }
    }

    function resume() {
        try {
            const saved = JSON.parse(localStorage.getItem(STORE) || 'null');
            if (!saved || !PHASE_LABEL[saved.mode] || saved.endsAt <= Date.now()) { forget(); return; }
            switchMode(saved.mode, false);
            totalDurationSeconds = saved.total;
            secondsRemaining = Math.round((saved.endsAt - Date.now()) / 1000);
            startTimer();
            endsAt = saved.endsAt; // keep the original end, not "now + what is left"
            remember();
            tick();
        } catch { forget(); }
    }

    function skipTimer() {
        confirmAction('ข้ามช่วงนี้ไปช่วงถัดไป รอบนี้จะไม่ถูกบันทึก', 'ข้ามช่วงนี้', 'ข้ามช่วงนี้?').then(confirmed => {
            if (confirmed) handleTimerFinished(true);
        });
    }

    async function handleTimerFinished(skipped = false) {
        pauseTimer(false);
        if (!skipped) playFocusChime();

        if (currentMode === 'work' && !skipped) {
            const taskId = selectFocusTask.value;
            try {
                await apiFetch(BASE_URL + '/api/focus', {
                    method: 'POST',
                    body: JSON.stringify({
                        type: currentMode,
                        duration_min: Math.floor(totalDurationSeconds / 60),
                        task_id: taskId ? parseInt(taskId, 10) : null,
                        title: inputFocusTitle.value.trim()
                    })
                });
                inputFocusTitle.value = '';
                toast('จบรอบโฟกัส บันทึกแล้ว');
                fetchLogs();
            } catch (err) {
                console.error(err);
                toast('จบรอบแล้ว แต่บันทึกไม่สำเร็จ ลองรีเฟรชหน้าแล้วจดเวลาด้วยตัวเอง', 'danger');
            }
        } else if (!skipped) {
            toast('หมดเวลาพัก ถึงเวลาโฟกัส');
        }

        // On to the next phase, stopped: starting it is the person's choice.
        switchMode(currentMode === 'work' ? 'short_break' : 'work', false);
    }

    // Web Audio API synthesized bell
    function playFocusChime() {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;
            const ctx = new AudioContextClass();

            [[523.25, 0], [659.25, 0.15]].forEach(([frequency, delay]) => {
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(frequency, ctx.currentTime + delay);
                gain.gain.setValueAtTime(0.15, ctx.currentTime + delay);
                gain.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + delay + 0.6);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(ctx.currentTime + delay);
                osc.stop(ctx.currentTime + delay + 0.6);
            });
        } catch (e) {
            console.error('Web Audio chime sound failed:', e);
        }
    }

    /* ---------- today's figures and the history ---------- */
    async function fetchLogs() {
        try {
            const res = await apiFetch(BASE_URL + '/api/focus');
            logsEl.removeAttribute('aria-busy');
            renderStats(res.stats);
            renderLogs(res.sessions);
        } catch (err) {
            console.error(err);
            logsEl.removeAttribute('aria-busy');
            logsEl.innerHTML = '<div class="alert alert-danger" role="alert">โหลดประวัติไม่สำเร็จ ตรวจการเชื่อมต่อแล้วลองใหม่ '
                + '<button type="button" class="btn btn-sm" id="focusRetry">ลองอีกครั้ง</button></div>';
            document.getElementById('focusRetry').addEventListener('click', fetchLogs);
            document.getElementById('focusTally').textContent = 'โหลดสถิติไม่ได้';
        }
    }

    function hoursMinutes(minutes) {
        return Math.floor(minutes / 60) + ':' + String(minutes % 60).padStart(2, '0');
    }

    function renderStats(stats) {
        if (!stats) return;
        const minutes = Number(stats.today_work_minutes) || 0;
        document.getElementById('statTodayTime').innerHTML = hoursMinutes(minutes) + '<small>ชม.</small>';
        document.getElementById('statTodaySessions').innerHTML = (stats.today_work_sessions || 0) + '<small>รอบ</small>';
        document.getElementById('statTotalSessions').innerHTML = (stats.total_sessions_count || 0) + '<small>รอบ</small>';
        document.getElementById('focusTally').textContent = stats.today_work_sessions > 0
            ? 'วันนี้โฟกัสแล้ว ' + hoursMinutes(minutes) + ' ชม. · ' + stats.today_work_sessions + ' รอบ'
            : 'วันนี้ยังไม่ได้จับเวลา';
    }

    function renderLogs(sessions) {
        if (!sessions || sessions.length === 0) {
            logsEl.innerHTML = '<div class="empty-state"><p class="empty-state-title">ยังไม่มีประวัติ</p>'
                + '<p class="empty-state-text">จับเวลารอบแรกให้ครบ แล้วรอบนั้นจะขึ้นที่นี่</p></div>';
            return;
        }

        logsEl.innerHTML = sessions.map(s => {
            const when = parseStamp(s.completed_at);
            const text = s.title ? escHtml(s.title) : (TYPE_LABEL[s.type] || escHtml(s.type));
            const task = s.task_title ? ' · งาน: ' + escHtml(s.task_title) : '';
            return '<div class="focus-log' + (s.type === 'work' ? '' : ' is-break') + '">'
                + '<div class="grow"><span class="title">' + text + '</span>'
                + '<span class="meta">' + when + ' · ' + (TYPE_LABEL[s.type] || escHtml(s.type)) + task + '</span></div>'
                + '<span class="side">' + s.duration_min + ' นาที</span>'
                + '<button type="button" class="icon-btn sm danger btn-delete-log" data-id="' + s.id + '" aria-label="ลบประวัติ: ' + text + '"><svg class="icon" aria-hidden="true"><use href="#i-trash"/></svg></button>'
                + '</div>';
        }).join('');

        logsEl.querySelectorAll('.btn-delete-log').forEach(btn => {
            btn.addEventListener('click', async () => {
                if (!await confirmAction('ลบประวัติรอบนี้แล้วกู้คืนไม่ได้', 'ลบประวัติ', 'ลบประวัติรอบนี้?')) return;
                try {
                    await apiFetch(BASE_URL + '/api/focus/' + btn.dataset.id, { method: 'DELETE' });
                    toast('ลบประวัติแล้ว');
                    fetchLogs();
                } catch (err) {
                    console.error(err);
                    toast('ลบไม่สำเร็จ ลองอีกครั้ง', 'danger');
                }
            });
        });
    }

    /** "2 ต.ค. 14:30" from a MySQL datetime. */
    function parseStamp(stamp) {
        const d = new Date(String(stamp).replace(' ', 'T'));
        if (isNaN(d)) return escHtml(String(stamp));
        return d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short' }) + ' ' + String(stamp).substring(11, 16);
    }

    switchMode('work', false);
    resume();
});
