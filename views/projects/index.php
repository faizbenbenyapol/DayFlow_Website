<?php
// =====================================================
// views/projects/index.php — Projects Planner Page
// =====================================================
?>

<script>
    const CURRENT_USER_ID = <?= (int)Auth::userId() ?>;
    const ACTIVE_PROJECT_ID_OVERRIDE = <?= (int)($projectIdOverride ?? 0) ?>;
    const CURRENT_GUEST_NAME = <?= json_encode($_SESSION['guest_name'] ?? null, JSON_UNESCAPED_UNICODE) ?>;
</script>

<div class="projects-layout">
    
    <!-- ฝั่งซ้าย: โครงการ และ บอร์ดคัมบังหลัก -->
    <div class="projects-main-content">
        
        <!-- ส่วนหัว: หัวข้อหน้า และ ปุ่มเครื่องมือด้านบน -->
        <div class="flex justify-between items-center mb-2 flex-wrap gap-3">
            <div>
                <h1 class="page-title">วางแผนโปรเจค (Project Planner)</h1>
                <p class="page-subtitle">จัดการ จัดลำดับความสำคัญ และติดตามงานของคุณด้วย Kanban Board อัจฉริยะ</p>
            </div>
            <div>
                <button class="btn btn-primary" onclick="openCreateProjectModal()">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
                    สร้างโปรเจคใหม่
                </button>
            </div>
        </div>

        <!-- แถบตัวกรองการแสดงผลโปรเจค (Filter, Search & Sort) -->
        <div class="projects-filter-bar">
            <div class="filter-left">
                <div class="search-input-wrap">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" x2="16.65" y1="21" y2="16.65"/></svg>
                    <input type="text" id="projectSearch" class="form-control projects-search" placeholder="ค้นหาโปรเจค..." oninput="filterProjects()">
                </div>
                <select id="projectStatusFilter" class="form-control filter-select" onchange="filterProjects()">
                    <option value="">ทุกสถานะ</option>
                    <option value="Planning">Planning</option>
                    <option value="In Progress">In Progress</option>
                    <option value="Review">Review</option>
                    <option value="Completed">Completed</option>
                </select>
                <select id="projectPriorityFilter" class="form-control filter-select" onchange="filterProjects()">
                    <option value="">ทุกความสำคัญ</option>
                    <option value="Critical">Critical</option>
                    <option value="High">High</option>
                    <option value="Medium">Medium</option>
                    <option value="Low">Low</option>
                </select>
            </div>
            <div class="filter-right">
                <select id="projectSort" class="form-control filter-select" style="width: 195px;" onchange="filterProjects()">
                    <option value="priority">จัดตามลำดับความสำคัญ</option>
                    <option value="due_date">จัดตามวันส่ง (Due Date)</option>
                    <option value="name">จัดตามชื่อ ก-ฮ</option>
                </select>
            </div>
        </div>

        <!-- แถบแจ้งเตือนเดดไลน์เร่งด่วนอัจฉริยะ (Smart Deadline Warning Banner) -->
        <div id="smartWarningBanner" class="smart-warning-banner" style="display: none;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" x2="12" y1="9" y2="13"/><line x1="12" x2="12" y1="17" y2="17"/></svg>
            <span id="smartWarningText">โปรเจคค้างส่งเลยกำหนดปลายปี กรุณาอัปเดตข้อมูล</span>
        </div>

        <!-- 2. ตารางโปรเจค (Project Cards Grid) -->
        <div id="projectsGrid" class="projects-grid">
            <!-- ดึงโปรเจคมาแสดงผลทาง JS -->
        </div>

        <!-- หน้ากรณีไม่มีข้อมูลโปรเจค (Empty State) -->
        <div id="projectsEmptyState" class="card" style="display: none; text-align: center; padding: 4rem 2rem;">
            <div style="margin-bottom: var(--space-4); color: var(--color-muted-2);">
                <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5v-15A2.5 2.5 0 0 1 6.5 2H20v20H6.5a2.5 2.5 0 0 1-2.5-2.5Z"/><path d="M6 6h10"/><path d="M6 10h10"/><path d="M6 14h10"/></svg>
            </div>
            <h3 class="empty-state-title">ไม่พบโปรเจคในขณะนี้</h3>
            <p class="empty-state-text text-muted mb-6">คุณยังไม่มีโครงการที่บันทึกไว้ในหน้าวางแผน มาสร้างโปรเจคแรกของคุณตอนนี้เลย!</p>
            <button class="btn btn-primary" onclick="openCreateProjectModal()">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
                สร้างโปรเจคแรกของคุณ
            </button>
        </div>

        <!-- ส่วนบอร์ดคัมบังของโปรเจคที่เลือก (Kanban Board Section) -->
        <div id="kanbanBoardSection" style="display: none; margin-top: var(--space-4);">
            <div class="flex justify-between items-center mb-4 flex-wrap gap-2">
                <div class="kanban-section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M9 3v18"/><path d="M15 3v18"/></svg>
                    <span>บอร์ดคัมบังโครงการ: </span>
                    <strong id="activeProjectTitle" class="text-sm font-semibold" style="color:var(--color-primary);">ชื่อโปรเจคที่เปิดใช้งาน</strong>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <button class="btn btn-ghost btn-sm" id="btnInviteMember" onclick="openInviteMemberModal()" style="display: none;">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                        ผู้เข้าร่วม (<span id="memberCountBadge">1</span>)
                    </button>
                    <button class="btn btn-ghost btn-sm" id="btnEditProject" onclick="openEditProjectModal()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                        แก้ไขโปรเจค
                    </button>
                    <button class="btn btn-danger btn-sm" id="btnDeleteProject" onclick="deleteActiveProject()">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/></svg>
                        ลบโปรเจค
                    </button>
                </div>
            </div>

            <!-- บอร์ดคัมบัง 4 คอลัมน์หลัก -->
            <div class="kanban-board">
                
                <!-- 1. คอลัมน์: To Do -->
                <div class="kanban-column" id="col-todo-wrap">
                    <div class="kanban-column-header">
                        <div class="kanban-column-title-wrap">
                            <span class="kanban-column-dot dot-todo"></span>
                            <span class="kanban-column-title">To Do</span>
                        </div>
                        <span class="kanban-column-count" id="todo-count">0</span>
                    </div>
                    <div class="kanban-cards-list" id="todo-list" data-status="To Do">
                        <!-- การ์ดงานคัมบังดึงทาง JS -->
                    </div>
                    <button class="kanban-quick-add-btn" onclick="toggleQuickAddForm('To Do')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
                        เพิ่มงานด่วน
                    </button>
                    <div class="kanban-quick-add-form" id="quickadd-todo-form">
                        <input type="text" class="form-control text-sm mb-2" id="quickadd-todo-input" placeholder="พิมพ์ชื่องานแล้วกด Enter..." onkeydown="handleQuickAddKey(event, 'To Do')">
                        <div class="flex justify-end gap-2">
                            <button class="btn btn-ghost btn-sm" onclick="toggleQuickAddForm('To Do', false)">ยกเลิก</button>
                            <button class="btn btn-primary btn-sm" onclick="submitQuickAdd('To Do')">เพิ่ม</button>
                        </div>
                    </div>
                </div>

                <!-- 2. คอลัมน์: In Progress -->
                <div class="kanban-column" id="col-inprogress-wrap">
                    <div class="kanban-column-header">
                        <div class="kanban-column-title-wrap">
                            <span class="kanban-column-dot dot-in-progress"></span>
                            <span class="kanban-column-title">In Progress</span>
                        </div>
                        <span class="kanban-column-count" id="inprogress-count">0</span>
                    </div>
                    <div class="kanban-cards-list" id="inprogress-list" data-status="In Progress">
                        <!-- การ์ดงานคัมบังดึงทาง JS -->
                    </div>
                    <button class="kanban-quick-add-btn" onclick="toggleQuickAddForm('In Progress')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
                        เพิ่มงานด่วน
                    </button>
                    <div class="kanban-quick-add-form" id="quickadd-inprogress-form">
                        <input type="text" class="form-control text-sm mb-2" id="quickadd-inprogress-input" placeholder="พิมพ์ชื่องานแล้วกด Enter..." onkeydown="handleQuickAddKey(event, 'In Progress')">
                        <div class="flex justify-end gap-2">
                            <button class="btn btn-ghost btn-sm" onclick="toggleQuickAddForm('In Progress', false)">ยกเลิก</button>
                            <button class="btn btn-primary btn-sm" onclick="submitQuickAdd('In Progress')">เพิ่ม</button>
                        </div>
                    </div>
                </div>

                <!-- 3. คอลัมน์: Review -->
                <div class="kanban-column" id="col-review-wrap">
                    <div class="kanban-column-header">
                        <div class="kanban-column-title-wrap">
                            <span class="kanban-column-dot dot-review"></span>
                            <span class="kanban-column-title">Review</span>
                        </div>
                        <span class="kanban-column-count" id="review-count">0</span>
                    </div>
                    <div class="kanban-cards-list" id="review-list" data-status="Review">
                        <!-- การ์ดงานคัมบังดึงทาง JS -->
                    </div>
                    <button class="kanban-quick-add-btn" onclick="toggleQuickAddForm('Review')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
                        เพิ่มงานด่วน
                    </button>
                    <div class="kanban-quick-add-form" id="quickadd-review-form">
                        <input type="text" class="form-control text-sm mb-2" id="quickadd-review-input" placeholder="พิมพ์ชื่องานแล้วกด Enter..." onkeydown="handleQuickAddKey(event, 'Review')">
                        <div class="flex justify-end gap-2">
                            <button class="btn btn-ghost btn-sm" onclick="toggleQuickAddForm('Review', false)">ยกเลิก</button>
                            <button class="btn btn-primary btn-sm" onclick="submitQuickAdd('Review')">เพิ่ม</button>
                        </div>
                    </div>
                </div>

                <!-- 4. คอลัมน์: Done -->
                <div class="kanban-column" id="col-done-wrap">
                    <div class="kanban-column-header">
                        <div class="kanban-column-title-wrap">
                            <span class="kanban-column-dot dot-done"></span>
                            <span class="kanban-column-title">Done</span>
                        </div>
                        <span class="kanban-column-count" id="done-count">0</span>
                    </div>
                    <div class="kanban-cards-list" id="done-list" data-status="Done">
                        <!-- การ์ดงานคัมบังดึงทาง JS -->
                    </div>
                    <button class="kanban-quick-add-btn" onclick="toggleQuickAddForm('Done')">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
                        เพิ่มงานด่วน
                    </button>
                    <div class="kanban-quick-add-form" id="quickadd-done-form">
                        <input type="text" class="form-control text-sm mb-2" id="quickadd-done-input" placeholder="พิมพ์ชื่องานแล้วกด Enter..." onkeydown="handleQuickAddKey(event, 'Done')">
                        <div class="flex justify-end gap-2">
                            <button class="btn btn-ghost btn-sm" onclick="toggleQuickAddForm('Done', false)">ยกเลิก</button>
                            <button class="btn btn-primary btn-sm" onclick="submitQuickAdd('Done')">เพิ่ม</button>
                        </div>
                    </div>
                </div>

            </div>
        </div>

    </div>

    <!-- ฝั่งขวา: วิดเจ็ตสรุปแดชบอร์ดด้านข้าง (Sidebar Widgets) -->
    <div class="projects-sidebar-widgets">
        
        <!-- วิดเจ็ต 1: รายงาน AI Summary วิเคราะห์โครงการ -->
        <div id="aiSummaryCardWrap" class="ai-summary-card" style="display: none;">
            <div class="ai-summary-header">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" class="ai-summary-sparkle"><path d="m12 3-1.912 5.813a2 2 0 0 1-1.275 1.275L3 12l5.813 1.912a2 2 0 0 1 1.275 1.275L12 21l1.912-5.813a2 2 0 0 1 1.275-1.275L21 12l-5.813-1.912a2 2 0 0 1-1.275-1.275L12 3Z"/></svg>
                <h2 class="ai-summary-title">AI PROJECT INSIGHTS</h2>
            </div>
            <p class="ai-summary-text" id="aiInsightText">กำลังวิเคราะห์ความก้าวหน้าโครงการของคุณ...</p>
            <div id="aiWarningBox" class="ai-summary-warning" style="display: none;">
                คำเตือน: โปรเจคเลยกำหนดส่งไปแล้ว
            </div>
        </div>

        <!-- วิดเจ็ต 2: แผงควบคุมข้อมูลสถิติความก้าวหน้าโครงการ (Analytics Module) -->
        <div id="analyticsWidgetCard" class="analytics-card" style="display: none;">
            <h2 class="analytics-title">วิเคราะห์ความก้าวหน้า</h2>
            <div class="analytics-stats-grid">
                <div class="stat-item">
                    <div class="stat-val" id="statCompletedTasks">0</div>
                    <div class="stat-lbl">เสร็จสิ้น (Done)</div>
                </div>
                <div class="stat-item">
                    <div class="stat-val" id="statRemainingTasks">0</div>
                    <div class="stat-lbl">ค้างคา (Active)</div>
                </div>
                <div class="stat-item">
                    <div class="stat-val" id="statProductivity">0%</div>
                    <div class="stat-lbl">อัตรางานสำเร็จ</div>
                </div>
            </div>
            <div class="analytics-chart-container">
                <canvas id="projectDoughnutChart" role="img" aria-label="กราฟความก้าวหน้าโครงการ"></canvas>
            </div>
        </div>

        <!-- วิดเจ็ต 3: ปฏิทินย่อแสดงวันส่งงานโครงการ (Mini Calendar) -->
        <div id="miniCalendarWidgetCard" class="mini-cal-card">
            <div class="mini-cal-header">
                <h2 class="mini-cal-title" id="calendarMonthTitle">พฤษภาคม 2569</h2>
                <div class="mini-cal-nav">
                    <button class="mini-cal-arrow" onclick="navCalendar(-1)">&larr;</button>
                    <button class="mini-cal-arrow" onclick="navCalendar(1)">&rarr;</button>
                </div>
            </div>
            <div class="mini-cal-grid" id="miniCalendarGrid">
                <!-- ดึงปฏิทินแสดงผลทาง JS -->
            </div>
        </div>

        <!-- วิดเจ็ต 4: บันทึกประวัติกิจกรรมล่าสุด (Activity Feed Widget) -->
        <div id="activityWidgetCard" class="activity-card" style="display: none;">
            <h2 class="analytics-title" style="margin-bottom: var(--space-4);">ประวัติกิจกรรมโปรเจค</h2>
            <div class="activity-feed-list" id="projectActivityList">
                <!-- ไทม์ไลน์กิจกรรมจะดึงผ่าน JS -->
            </div>
        </div>

        <!-- วิดเจ็ต 5: ระบบแชทสดในแต่ละโครงการ (Project Live Chat Widget) -->
        <div id="chatWidgetCard" class="chat-card card" style="display: none;">
            <div class="chat-card-header">
                <div class="flex items-center gap-2">
                    <span class="chat-status-dot"></span>
                    <h2 class="analytics-title" style="margin-bottom: 0;">แชทในโครงการ</h2>
                </div>
                <div class="flex items-center gap-2">
                    <span id="guestRenameContainer" style="display: none;">
                        <button class="btn btn-ghost btn-xs text-muted flex items-center gap-1" onclick="changeGuestName()" title="แก้ไขชื่อเล่นของคุณ" style="font-size: 0.72rem; padding: 2px 6px; border: 1px solid var(--color-border); border-radius: var(--radius-sm); background: var(--color-surface); cursor: pointer;">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:2px;"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                            <span id="lblGuestName">คุณ: ...</span>
                        </button>
                    </span>
                    <span class="chat-status-text" id="chatStatusText">กำลังเชื่อมต่อ...</span>
                </div>
            </div>
            <div class="chat-messages-container" id="chatMessagesList">
                <!-- รายการแชทจะดึงผ่าน JS -->
            </div>
            <div class="chat-input-wrap">
                <input type="text" id="chatMessageInput" class="form-control chat-input" placeholder="พิมพ์ข้อความคุยกับทีม..." onkeydown="handleChatKeyDown(event)">
                <button type="button" class="btn btn-primary btn-chat-send" onclick="sendChatMessage()" title="ส่งข้อความ">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="22" x2="11" y1="2" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                </button>
            </div>
        </div>

    </div>

</div>

<!-- =====================================================
     หน้าต่างโต้ตอบการจัดการ (Modals Area)
     ===================================================== -->

<!-- 1. MODAL: สร้างโปรเจคใหม่ -->
<div class="modal-backdrop" id="createProjectModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">สร้างโปรเจคใหม่</h3>
            <button class="modal-close" onclick="closeModal('createProjectModal')">&times;</button>
        </div>
        <form id="createProjectForm" onsubmit="submitCreateProject(event)">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="newProjName">ชื่อโปรเจค <span style="color:var(--color-danger)">*</span></label>
                    <input type="text" id="newProjName" class="form-control" name="name" required placeholder="เช่น ออกแบบหน้าเว็บสเปซสตาร์ทอัพ">
                </div>
                <div class="form-group">
                    <label class="form-label" for="newProjDesc">คำอธิบายย่อ</label>
                    <textarea id="newProjDesc" class="form-control" name="description" rows="3" placeholder="ระบุขอบเขตงานคร่าวๆ ของโครงการย่อยนี้..."></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="newProjPriority">ความสำคัญ</label>
                        <select id="newProjPriority" class="form-control" name="priority">
                            <option value="Low">Low (ต่ำ)</option>
                            <option value="Medium" selected>Medium (ปานกลาง)</option>
                            <option value="High">High (สูง)</option>
                            <option value="Critical">Critical (วิกฤต)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="newProjStatus">สถานะหลัก</label>
                        <select id="newProjStatus" class="form-control" name="status">
                            <option value="Planning" selected>Planning</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Review">Review</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="newProjDue">วันสิ้นสุดการส่งโปรเจค (Due Date)</label>
                    <input type="date" id="newProjDue" class="form-control" name="due_date">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('createProjectModal')">ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึกโครงการ</button>
            </div>
        </form>
    </div>
</div>

<!-- 2. MODAL: แก้ไขข้อมูลโปรเจคหลัก -->
<div class="modal-backdrop" id="editProjectModal">
    <div class="modal">
        <div class="modal-header">
            <h3 class="modal-title">แก้ไขรายละเอียดโปรเจค</h3>
            <button class="modal-close" onclick="closeModal('editProjectModal')">&times;</button>
        </div>
        <form id="editProjectForm" onsubmit="submitEditProject(event)">
            <input type="hidden" id="editProjId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="editProjName">ชื่อโปรเจค <span style="color:var(--color-danger)">*</span></label>
                    <input type="text" id="editProjName" class="form-control" required>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editProjDesc">คำอธิบายย่อ</label>
                    <textarea id="editProjDesc" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="editProjPriority">ความสำคัญ</label>
                        <select id="editProjPriority" class="form-control">
                            <option value="Low">Low (ต่ำ)</option>
                            <option value="Medium">Medium (ปานกลาง)</option>
                            <option value="High">High (สูง)</option>
                            <option value="Critical">Critical (วิกฤต)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editProjStatus">สถานะหลัก</label>
                        <select id="editProjStatus" class="form-control">
                            <option value="Planning">Planning</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Review">Review</option>
                            <option value="Completed">Completed</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editProjDue">วันสิ้นสุดการส่งโปรเจค (Due Date)</label>
                    <input type="date" id="editProjDue" class="form-control">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editProjectModal')">ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
            </div>
        </form>
    </div>
</div>

<!-- 3. MODAL: แก้ไขข้อมูลรายละเอียดงานย่อยบนบอร์ด (Task Info Dialog) & ระบบเช็คลิสต์ -->
<div class="modal-backdrop" id="editTaskModal">
    <div class="modal" style="max-width: 600px;">
        <div class="modal-header">
            <h3 class="modal-title">แก้ไขรายละเอียดงานย่อยบนบอร์ด</h3>
            <button class="modal-close" onclick="closeModal('editTaskModal')">&times;</button>
        </div>
        <form id="editTaskForm" onsubmit="submitEditTask(event)">
            <input type="hidden" id="editTaskId">
            <div class="modal-body" style="padding-bottom: 0;">
                
                <div class="form-group">
                    <label class="form-label" for="editTaskTitle">หัวข้องานย่อย <span style="color:var(--color-danger)">*</span></label>
                    <input type="text" id="editTaskTitle" class="form-control" required placeholder="ระบุหัวข้องานย่อย...">
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="editTaskStatus">สถานะในบอร์ด</label>
                        <select id="editTaskStatus" class="form-control">
                            <option value="To Do">To Do</option>
                            <option value="In Progress">In Progress</option>
                            <option value="Review">Review</option>
                            <option value="Done">Done</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editTaskPriority">ความสำคัญ</label>
                        <select id="editTaskPriority" class="form-control">
                            <option value="Low">Low (ต่ำ)</option>
                            <option value="Medium">Medium (ปานกลาง)</option>
                            <option value="High">High (สูง)</option>
                            <option value="Critical">Critical (วิกฤต)</option>
                        </select>
                    </div>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="editTaskCategory">หมวดหมู่ / แท็ก</label>
                        <input type="text" id="editTaskCategory" class="form-control" placeholder="เช่น Design, Code, Copywrite">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editTaskAssignee">ผู้รับผิดชอบงาน (Assignee)</label>
                        <select id="editTaskAssignee" class="form-control">
                            <option value="">เลือกผู้รับผิดชอบ...</option>
                            <option value="Alex">Alex (ดีไซเนอร์)</option>
                            <option value="Jordan">Jordan (ฟรอนต์เอนด์)</option>
                            <option value="Taylor">Taylor (แบ็กเอนด์)</option>
                            <option value="Me">ตัวเอง (Me)</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" for="editTaskDue">วันครบกำหนดส่งงานย่อย (Due Date)</label>
                    <input type="date" id="editTaskDue" class="form-control">
                </div>

                <!-- ระบบเครื่องมือเช็คลิสต์ย่อยภายในบอร์ด (Modal Sub-Checklist Manager) -->
                <div class="modal-checklist-container">
                    <div class="checklist-header">
                        <span>เช็คลิสต์ย่อยในชิ้นงาน</span>
                        <span id="checklistPercentageLabel">0% เสร็จสิ้น</span>
                    </div>
                    <div class="checklist-progress-bar-wrap">
                        <div class="checklist-progress-fill" id="checklistProgressFill"></div>
                    </div>
                    <div class="checklist-list" id="modalChecklistList">
                        <!-- รายงานช่องเช็คลิสต์ดึงผ่าน JS -->
                    </div>
                    <div class="flex gap-2 items-center">
                        <input type="text" class="form-control text-sm" id="newChecklistItemInput" placeholder="เพิ่มเช็คลิสต์ย่อยใหม่ในงานนี้..." style="height: 34px;">
                        <button type="button" class="btn btn-ghost btn-sm" onclick="addChecklistItem()" style="height: 34px;">เพิ่มชิ้นย่อย</button>
                    </div>
                </div>

            </div>
            <div class="modal-footer" style="margin-top:var(--space-4);">
                <button type="button" class="btn btn-ghost" onclick="closeModal('editTaskModal')">ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึกข้อมูลงาน</button>
            </div>
        </form>
    </div>
</div>

<!-- 4. MODAL: จัดการสมาชิกโครงการ & เชิญผู้ร่วมทีม -->
<div class="modal-backdrop" id="inviteMemberModal">
    <div class="modal" style="max-width: 550px;">
        <div class="modal-header">
            <h3 class="modal-title">สมาชิกและผู้รับผิดชอบโครงการ</h3>
            <button class="modal-close" onclick="closeModal('inviteMemberModal')">&times;</button>
        </div>
        <div class="modal-body" style="padding-bottom: var(--space-4);">
            
            <!-- รายชื่อสมาชิกปัจจุบัน -->
            <div class="mb-4">
                <label class="form-label mb-2" style="font-weight: 600;">ผู้ร่วมทีมปัจจุบัน</label>
                <div class="members-list-wrapper" id="projectMembersList">
                    <!-- โหลดสมาชิกจาก JS -->
                </div>
            </div>

            <!-- ฟอร์มเชิญสมาชิกใหม่ (แสดงเฉพาะสำหรับ Owner) -->
            <form id="inviteMemberForm" onsubmit="submitInviteMember(event)" style="display: none;">
                <hr style="border: 0; border-top: 1px solid var(--color-border); margin: var(--space-4) 0;">
                <label class="form-label mb-2" style="font-weight: 600;">เชิญผู้ร่วมทีมคนใหม่</label>
                <div class="form-group mb-3">
                    <label class="form-label" for="inviteSearchInput" style="font-size: 0.8rem;">ชื่อผู้ใช้งาน หรือ อีเมลผู้รับเชิญ</label>
                    <input type="text" id="inviteSearchInput" class="form-control" placeholder="ระบุ username หรือ email ของผู้ใช้ในระบบ..." required autocomplete="off">
                </div>
                <div class="form-group mb-3">
                    <label class="form-label" for="inviteRoleSelect" style="font-size: 0.8rem;">บทบาทสิทธิ์ (Role)</label>
                    <select id="inviteRoleSelect" class="form-control">
                        <option value="Editor" selected>Editor (เพิ่ม/แก้ไขงานย่อยได้)</option>
                        <option value="Viewer">Viewer (เข้าชมและแชทได้อย่างเดียว)</option>
                    </select>
                </div>
                <div class="flex justify-end mt-2">
                    <button type="submit" class="btn btn-primary btn-sm">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="margin-right:4px;"><line x1="12" x2="12" y1="5" y2="19"/><line x1="5" x2="19" y1="12" y2="12"/></svg>
                        ส่งคำเชิญเข้าร่วม
                    </button>
                </div>
            </form>

            <!-- ลิงก์แชร์โครงการสาธารณะ (Public Share Link) -->
            <div id="publicShareSection" style="display: none;">
                <hr style="border: 0; border-top: 1px solid var(--color-border); margin: var(--space-4) 0;">
                <label class="form-label mb-2" style="font-weight: 600; display: flex; align-items: center; gap: 6px;">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="color: #06b6d4;"><path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/></svg>
                    ลิงก์แชร์โครงการสาธารณะ (Public Share Link)
                </label>
                <div style="background: var(--color-surface-2); border: 1px solid var(--color-border); padding: var(--space-4); border-radius: var(--radius-md); margin-bottom: var(--space-2);">
                    <div class="flex justify-between items-center mb-3">
                        <span class="text-xs font-semibold text-muted" style="font-size: 0.8rem;">เปิดให้บุคคลภายนอกเข้าใช้งานผ่านลิงก์โดยไม่จำเป็นต้องมีบัญชี</span>
                        <label class="switch">
                            <input type="checkbox" id="shareLinkToggle" onchange="togglePublicShare()">
                            <span class="slider"></span>
                        </label>
                    </div>
                    <div id="shareLinkDetails" style="display: none;">
                        <div class="form-group mb-3">
                            <label class="form-label" for="shareLinkRole" style="font-size: 0.8rem; font-weight: 500;">กำหนดสิทธิ์เข้าถึงผ่านลิงก์</label>
                            <select id="shareLinkRole" class="form-control" onchange="updateShareRole()">
                                <option value="Viewer">Viewer (อ่านบอร์ด และร่วมแชทได้อย่างเดียว)</option>
                                <option value="Editor">Editor (จัดการงานย่อย บันทึกความคืบหน้า และแชทได้)</option>
                            </select>
                        </div>
                        <div class="form-group mb-0">
                            <label class="form-label" style="font-size: 0.8rem; font-weight: 500;">ที่อยู่อีเมล/ลิงก์สาธารณะ</label>
                            <div class="flex gap-2">
                                <input type="text" id="shareLinkUrl" class="form-control" readonly style="background: var(--color-surface); font-family: monospace; font-size: 0.8rem; cursor: text;">
                                <button type="button" class="btn btn-primary btn-sm" onclick="copyShareUrl()" style="white-space: nowrap; height: 38px;">
                                    คัดลอกลิงก์
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
