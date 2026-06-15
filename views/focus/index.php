<div class="page-header flex items-center justify-between" style="border-bottom: 1px solid var(--color-border); padding-bottom: var(--space-4); margin-bottom: var(--space-6);">
    <div>
        <h1 class="page-title" style="font-size: 2.2rem; font-weight: 800; background: linear-gradient(135deg, var(--color-text) 30%, var(--color-muted) 100%); -webkit-background-clip: text; -webkit-text-fill-color: transparent; display: flex; align-items: center; gap: var(--space-3);">
            <span>โฟกัสแบบ Pomodoro</span>
            <span class="active-status-dot" title="ระบบจับเวลาทำงานปกติ"></span>
        </h1>
        <p class="text-xs text-muted" style="margin-top: 4px;">ปรับเปลี่ยนช่วงเวลาเรียน/ทำงาน และพักผ่อนเพื่อการโฟกัสสูงสุด</p>
    </div>
</div>

<div class="focus-layout-grid">
    <!-- Left Column: The Timer Card -->
    <div class="focus-timer-section">
        <div class="card focus-card text-center">
            <div class="card-body">
                <!-- Mode tabs selector -->
                <div class="focus-mode-selector">
                    <button class="focus-mode-btn active" data-mode="work">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="16" y2="12"/><line x1="12" x2="12" y1="8" y2="8"/></svg>
                        โฟกัสงาน
                    </button>
                    <button class="focus-mode-btn" data-mode="short_break">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8h1a4 4 0 0 1 0 8h-1"/><path d="M2 8h16v9a4 4 0 0 1-4 4H6a4 4 0 0 1-4-4V8z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/></svg>
                        พักระยะสั้น
                    </button>
                    <button class="focus-mode-btn" data-mode="long_break">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 2v20"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                        พักระยะยาว
                    </button>
                </div>

                <!-- Circular Visual countdown -->
                <div class="timer-visual-container">
                    <svg class="timer-svg" viewBox="0 0 220 220">
                        <circle class="timer-track" cx="110" cy="110" r="95"></circle>
                        <circle class="timer-progress" id="timerProgress" cx="110" cy="110" r="95"></circle>
                    </svg>
                    <div class="timer-content">
                        <div class="timer-phase-lbl" id="timerPhaseLabel">พร้อมโฟกัส</div>
                        <div class="timer-countdown" id="timerDisplay">25:00</div>
                    </div>
                </div>

                <!-- Timer controls buttons -->
                <div class="timer-controls">
                    <button class="btn btn-ghost btn-circle" id="btnResetTimer" title="รีเซ็ต">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 0 1 9-9 9.75 9.75 0 0 1 6.74 2.74L21 8"/><path d="M21 3v5h-5"/><path d="M21 12a9 9 0 0 1-9 9 9.75 9.75 0 0 1-6.74-2.74L3 16"/><path d="M3 21v-5h5"/></svg>
                    </button>
                    <button class="btn btn-primary btn-lg btn-circle-play" id="btnStartStop" title="เริ่ม/หยุดชั่วคราว">
                        <svg width="24" height="24" id="playIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                        <svg width="24" height="24" id="pauseIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="display:none"><rect x="6" y="4" width="4" height="16"></rect><rect x="14" y="4" width="4" height="16"></rect></svg>
                    </button>
                    <button class="btn btn-ghost btn-circle" id="btnSkipTimer" title="ข้าม/เสร็จสิ้นก่อนกำหนด">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 4 15 12 5 20 5 4"/><line x1="19" x2="19" y1="5" y2="19"/></svg>
                    </button>
                </div>

                <!-- Focus settings toggle -->
                <details class="timer-custom-settings">
                    <summary>ปรับแต่งเวลาจับเวลา</summary>
                    <div class="custom-settings-fields">
                        <div class="form-group">
                            <label class="form-label">ช่วงเวลาทำงาน (นาที)</label>
                            <input type="number" class="form-control" id="inputWorkDuration" value="25" min="1" max="180">
                        </div>
                        <div class="form-group">
                            <label class="form-label">ช่วงพักสั้น (นาที)</label>
                            <input type="number" class="form-control" id="inputShortBreak" value="5" min="1" max="60">
                        </div>
                        <div class="form-group">
                            <label class="form-label">ช่วงพักยาว (นาที)</label>
                            <input type="number" class="form-control" id="inputLongBreak" value="15" min="1" max="120">
                        </div>
                    </div>
                </details>

                <hr style="border: 0; border-top: 1px solid var(--color-border); margin: var(--space-4) 0;">

                <!-- Focus integration: Task and log inputs -->
                <div class="focus-log-setup text-left" style="width: 100%; text-align: left;">
                    <div class="form-group">
                        <label class="form-label">เชื่อมโยงกับงาน (เลือกเพื่อระบุลงในระบบงาน)</label>
                        <select class="form-control" id="selectFocusTask" style="width: 100%;">
                            <option value="">-- ไม่ระบุงาน --</option>
                            <?php foreach ($openTasks as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= h($t['title']) ?> (Q<?= (int)$t['quadrant'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label">หัวข้อความสนใจ / สรุปช่วงเวลา</label>
                        <input type="text" class="form-control" id="inputFocusTitle" placeholder="เช่น ศึกษาโครงสร้างฐานข้อมูล, ตอบอีเมลลูกค้า...">
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Right Column: Stats & Logs -->
    <div class="focus-stats-section">
        <!-- Stats strip cards -->
        <div class="focus-stats-strip">
            <div class="focus-stat-card">
                <div class="focus-stat-icon red">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                </div>
                <div class="focus-stat-content">
                    <div class="focus-stat-val" id="statTodayTime">0 นาที</div>
                    <div class="focus-stat-lbl">เวลาทำงานวันนี้</div>
                </div>
            </div>
            <div class="focus-stat-card">
                <div class="focus-stat-icon green">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <div class="focus-stat-content">
                    <div class="focus-stat-val" id="statTodaySessions">0 รอบ</div>
                    <div class="focus-stat-lbl">รอบโฟกัสสำเร็จวันนี้</div>
                </div>
            </div>
            <div class="focus-stat-card">
                <div class="focus-stat-icon purple">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M9 3v18"/><path d="M15 3v18"/><path d="M3 9h18"/><path d="M3 15h18"/></svg>
                </div>
                <div class="focus-stat-content">
                    <div class="focus-stat-val" id="statTotalSessions">0 รอบ</div>
                    <div class="focus-stat-lbl">สะสมทั้งหมด</div>
                </div>
            </div>
        </div>

        <!-- History logs card -->
        <div class="card" style="margin-top: var(--space-4);">
            <div class="card-header flex items-center justify-between">
                <span class="card-title">ประวัติช่วงเวลาสมาธิ</span>
            </div>
            <div class="card-body" style="padding: 0;">
                <div class="table-wrap">
                    <table class="table" style="margin-bottom: 0;">
                        <thead>
                            <tr>
                                <th>วันที่/เวลา</th>
                                <th>งาน/คำอธิบาย</th>
                                <th>ประเภท</th>
                                <th>ระยะเวลา</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="focusLogsTableBody">
                            <tr>
                                <td colspan="5" class="text-center text-muted" style="padding: var(--space-5);">กำลังโหลดประวัติ...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
