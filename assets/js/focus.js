/* =====================================================
   focus.js — Premium Pomodoro Focus Controller
   ===================================================== */

document.addEventListener('DOMContentLoaded', function () {
    const gridLayout = document.querySelector('.focus-layout-grid');
    const timerDisplay = document.getElementById('timerDisplay');
    const timerPhaseLabel = document.getElementById('timerPhaseLabel');
    const timerProgress = document.getElementById('timerProgress');
    
    const btnStartStop = document.getElementById('btnStartStop');
    const btnResetTimer = document.getElementById('btnResetTimer');
    const btnSkipTimer = document.getElementById('btnSkipTimer');
    const playIcon = document.getElementById('playIcon');
    const pauseIcon = document.getElementById('pauseIcon');

    const inputWorkDuration = document.getElementById('inputWorkDuration');
    const inputShortBreak = document.getElementById('inputShortBreak');
    const inputLongBreak = document.getElementById('inputLongBreak');
    
    const selectFocusTask = document.getElementById('selectFocusTask');
    const inputFocusTitle = document.getElementById('inputFocusTitle');
    const logsTableBody = document.getElementById('focusLogsTableBody');

    // Stats Elements
    const statTodayTime = document.getElementById('statTodayTime');
    const statTodaySessions = document.getElementById('statTodaySessions');
    const statTotalSessions = document.getElementById('statTotalSessions');

    // State
    let timerInterval = null;
    let secondsRemaining = 25 * 60;
    let totalDurationSeconds = 25 * 60;
    let currentMode = 'work'; // 'work', 'short_break', 'long_break'
    let isRunning = false;

    // Load initial list and stats
    fetchLogs();

    // Mode Buttons Click
    document.querySelectorAll('.focus-mode-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const newMode = this.dataset.mode;
            switchMode(newMode);
        });
    });

    // Start/Stop Timer
    btnStartStop.addEventListener('click', toggleTimer);

    // Reset Timer
    btnResetTimer.addEventListener('click', resetTimer);

    // Skip Timer
    btnSkipTimer.addEventListener('click', skipTimer);

    // Dynamic input changes to reset timer duration if not running
    [inputWorkDuration, inputShortBreak, inputLongBreak].forEach(input => {
        input.addEventListener('change', function () {
            if (!isRunning) {
                switchMode(currentMode, false);
            }
        });
    });

    // Helper functions
    function getModeDuration(mode) {
        if (mode === 'work') return parseInt(inputWorkDuration.value) * 60;
        if (mode === 'short_break') return parseInt(inputShortBreak.value) * 60;
        if (mode === 'long_break') return parseInt(inputLongBreak.value) * 60;
        return 25 * 60;
    }

    function switchMode(mode, stopCurrent = true) {
        if (stopCurrent) {
            pauseTimer();
        }

        currentMode = mode;
        
        // Update Grid wrapper attribute
        if (gridLayout) {
            gridLayout.setAttribute('data-mode', mode);
        }

        // Highlight Active Mode tab button
        document.querySelectorAll('.focus-mode-btn').forEach(btn => {
            if (btn.dataset.mode === mode) {
                btn.classList.add('active');
            } else {
                btn.classList.remove('active');
            }
        });

        // Set durations
        const durationSeconds = getModeDuration(mode);
        secondsRemaining = durationSeconds;
        totalDurationSeconds = durationSeconds;

        // Update Labels & Display
        if (mode === 'work') {
            timerPhaseLabel.textContent = 'กำลังโฟกัสงาน';
        } else if (mode === 'short_break') {
            timerPhaseLabel.textContent = 'พักระยะสั้น';
        } else {
            timerPhaseLabel.textContent = 'พักระยะยาว';
        }

        updateDisplay();
    }

    function updateDisplay() {
        const mins = Math.floor(secondsRemaining / 60);
        const secs = secondsRemaining % 60;
        timerDisplay.textContent = `${mins.toString().padStart(2, '0')}:${secs.toString().padStart(2, '0')}`;

        // Progress SVG Ring offset (circumference = 597)
        const circumference = 597;
        const progress = secondsRemaining / totalDurationSeconds;
        const offset = circumference - (progress * circumference);
        timerProgress.style.strokeDashoffset = offset;
    }

    function toggleTimer() {
        if (isRunning) {
            pauseTimer();
        } else {
            startTimer();
        }
    }

    function startTimer() {
        isRunning = true;
        playIcon.style.display = 'none';
        pauseIcon.style.display = 'block';

        timerInterval = setInterval(() => {
            if (secondsRemaining > 0) {
                secondsRemaining--;
                updateDisplay();
            } else {
                handleTimerFinished();
            }
        }, 1000);
    }

    function pauseTimer() {
        isRunning = false;
        playIcon.style.display = 'block';
        pauseIcon.style.display = 'none';
        clearInterval(timerInterval);
        timerInterval = null;
    }

    function resetTimer() {
        switchMode(currentMode);
    }

    function skipTimer() {
        confirmAction('ต้องการข้ามช่วงเวลานี้หรือไม่?', 'ข้าม', 'ข้ามขั้นตอน').then(confirmed => {
            if (confirmed) {
                handleTimerFinished(true); // skip session logging or log early
            }
        });
    }

    async function handleTimerFinished(skipped = false) {
        pauseTimer();
        playFocusChime();

        if (currentMode === 'work' && !skipped) {
            // Log completed work session to database
            const taskId = selectFocusTask.value;
            const title = inputFocusTitle.value.trim();
            const minutesCompleted = Math.floor(totalDurationSeconds / 60);

            try {
                await apiFetch(BASE_URL + '/api/focus', {
                    method: 'POST',
                    body: JSON.stringify({
                        type: currentMode,
                        duration_min: minutesCompleted,
                        task_id: taskId ? parseInt(taskId) : null,
                        title: title
                    })
                });

                // Clear input title
                inputFocusTitle.value = '';
                showToast('โฟกัสสำเร็จ! บันทึกช่วงเวลาเรียบร้อยแล้ว', 'success');

                // Reload logs/stats
                fetchLogs();
            } catch (err) {
                console.error(err);
                showToast('ไม่สามารถบันทึกเซสชันโฟกัสได้', 'danger');
            }
        } else {
            showToast('หมดเวลาพักผ่อนแล้ว! ได้เวลาโฟกัสต่อ', 'success');
        }

        // Auto shift to next phase mode
        if (currentMode === 'work') {
            switchMode('short_break', false);
        } else {
            switchMode('work', false);
        }
    }

    // Web Audio API Synthesized Bell sound
    function playFocusChime() {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) return;
            const ctx = new AudioContextClass();
            
            // Tone 1: C5 (523.25 Hz)
            const osc1 = ctx.createOscillator();
            const gain1 = ctx.createGain();
            osc1.type = 'sine';
            osc1.frequency.setValueAtTime(523.25, ctx.currentTime);
            gain1.gain.setValueAtTime(0.15, ctx.currentTime);
            gain1.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.6);
            osc1.connect(gain1);
            gain1.connect(ctx.destination);
            osc1.start();
            osc1.stop(ctx.currentTime + 0.6);

            // Tone 2: E5 (659.25 Hz) at 0.15 seconds
            const osc2 = ctx.createOscillator();
            const gain2 = ctx.createGain();
            osc2.type = 'sine';
            osc2.frequency.setValueAtTime(659.25, ctx.currentTime + 0.15);
            gain2.gain.setValueAtTime(0.15, ctx.currentTime + 0.15);
            gain2.gain.exponentialRampToValueAtTime(0.01, ctx.currentTime + 0.75);
            osc2.connect(gain2);
            gain2.connect(ctx.destination);
            osc2.start(ctx.currentTime + 0.15);
            osc2.stop(ctx.currentTime + 0.75);
        } catch (e) {
            console.error('Web Audio chime sound failed:', e);
        }
    }

    // Fetch and render Focus Logs
    async function fetchLogs() {
        try {
            const res = await apiFetch(BASE_URL + '/api/focus');
            renderStats(res.stats);
            renderLogsTable(res.sessions);
        } catch (err) {
            console.error(err);
            if (logsTableBody) {
                logsTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-danger">เกิดข้อผิดพลาดในการโหลดประวัติ</td></tr>';
            }
        }
    }

    function renderStats(stats) {
        if (!stats) return;
        statTodayTime.textContent = `${stats.today_work_minutes} นาที`;
        statTodaySessions.textContent = `${stats.today_work_sessions} รอบ`;
        statTotalSessions.textContent = `${stats.total_sessions_count} รอบ`;
    }

    function renderLogsTable(sessions) {
        if (!logsTableBody) return;

        if (!sessions || sessions.length === 0) {
            logsTableBody.innerHTML = '<tr><td colspan="5" class="text-center text-muted" style="padding: var(--space-5);">ยังไม่มีประวัติการโฟกัสในระบบ</td></tr>';
            return;
        }

        let html = '';
        sessions.forEach(s => {
            const dateStr = formatDate(s.completed_at) + ' ' + s.completed_at.substring(11, 16);
            const titleText = s.task_title ? `${escHtml(s.title)} (งาน: ${escHtml(s.task_title)})` : escHtml(s.title);
            
            let typeBadge = '';
            if (s.type === 'work') {
                typeBadge = '<span class="badge-focus-type work">โฟกัสงาน</span>';
            } else if (s.type === 'short_break') {
                typeBadge = '<span class="badge-focus-type short_break">พักระยะสั้น</span>';
            } else {
                typeBadge = '<span class="badge-focus-type long_break">พักระยะยาว</span>';
            }

            html += `<tr>
                <td>${dateStr}</td>
                <td style="font-weight: 500;">${titleText}</td>
                <td>${typeBadge}</td>
                <td style="font-weight: 600;">${s.duration_min} นาที</td>
                <td class="text-right">
                    <button class="btn btn-ghost btn-sm btn-delete-log text-danger" data-id="${s.id}" style="padding: 4px;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                    </button>
                </td>
            </tr>`;
        });

        logsTableBody.innerHTML = html;

        // Bind delete buttons
        logsTableBody.querySelectorAll('.btn-delete-log').forEach(btn => {
            btn.addEventListener('click', function () {
                const id = this.dataset.id;
                confirmAction('คุณแน่ใจว่าต้องการลบประวัติรายการนี้ใช่หรือไม่?', 'ลบข้อมูล', 'ลบประวัติ').then(async confirmed => {
                    if (confirmed) {
                        try {
                            await apiFetch(BASE_URL + `/api/focus/${id}`, { method: 'DELETE' });
                            showToast('ลบรายการบันทึกเรียบร้อยแล้ว', 'success');
                            fetchLogs();
                        } catch (err) {
                            console.error(err);
                            showToast('ลบรายการไม่สำเร็จ', 'danger');
                        }
                    }
                });
            });
        });
    }

    // Helper formatting tools
    function escHtml(str) {
        if (!str) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDate(dateStr) {
        const d = new Date(dateStr);
        if (isNaN(d)) return dateStr;
        return d.toLocaleDateString('th-TH', { day: 'numeric', month: 'short', year: 'numeric' });
    }

    function showToast(msg, type = 'success') {
        const container = document.getElementById('toastContainer');
        if (!container) return;
        
        const toast = document.createElement('div');
        toast.className = `toast toast-${type} animate-fade-in`;
        toast.textContent = msg;
        container.appendChild(toast);
        
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
});
