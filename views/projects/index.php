<?php
// =====================================================
// views/projects/index.php — projects
//
// The projects as ruled rows with their progress; the open one as a four-column
// board (to do, doing, review, done) with the people, the figures, a calendar,
// what happened lately and a chat beneath. Guests of a shared project get the
// same page with the buttons their role allows. assets/js/projects*.js draw it
// from /api/projects/*.
// =====================================================
?>
<script nonce="<?= h(Security::nonce()) ?>">
    const CURRENT_USER_ID = <?= (int)Auth::userId() ?>;
    const ACTIVE_PROJECT_ID_OVERRIDE = <?= (int)($projectIdOverride ?? 0) ?>;
    const CURRENT_GUEST_NAME = <?= jsonForScript($_SESSION['guest_name'] ?? null) ?>;
</script>

<div class="page-head">
    <div>
        <h1>โปรเจค</h1>
        <p class="sub" id="projectsTally" aria-live="polite">กำลังโหลดโปรเจค…</p>
    </div>
    <div class="page-head-actions">
        <button class="btn btn-primary" type="button" data-act="openCreateProjectModal"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>สร้างโปรเจค</button>
    </div>
</div>

<div class="proj-filters">
    <label class="sr-only" for="projectSearch">ค้นหาโปรเจค</label>
    <input type="search" id="projectSearch" class="form-control proj-search" placeholder="ค้นหาโปรเจค" autocomplete="off" data-act="filterProjects" data-on="input">
    <label class="sr-only" for="projectStatusFilter">สถานะ</label>
    <select id="projectStatusFilter" class="form-control" data-act="filterProjects" data-on="change">
        <option value="">ทุกสถานะ</option>
        <option value="Planning">วางแผน</option>
        <option value="In Progress">กำลังทำ</option>
        <option value="Review">รอตรวจ</option>
        <option value="Completed">เสร็จแล้ว</option>
    </select>
    <label class="sr-only" for="projectPriorityFilter">ความสำคัญ</label>
    <select id="projectPriorityFilter" class="form-control" data-act="filterProjects" data-on="change">
        <option value="">ทุกความสำคัญ</option>
        <option value="Critical">วิกฤต</option>
        <option value="High">สูง</option>
        <option value="Medium">ปานกลาง</option>
        <option value="Low">ต่ำ</option>
    </select>
    <label class="sr-only" for="projectSort">เรียงตาม</label>
    <select id="projectSort" class="form-control" data-act="filterProjects" data-on="change">
        <option value="priority">เรียงตามความสำคัญ</option>
        <option value="due_date">เรียงตามวันส่ง</option>
        <option value="name">เรียงตามชื่อ</option>
    </select>
</div>

<div id="smartWarningBanner" class="alert alert-warning" role="alert" hidden>
    <span id="smartWarningText"></span>
</div>

<div id="projectsGrid" class="proj-list" aria-busy="true">
    <div class="skel-row"><span class="skel skel-w-60"></span></div>
    <div class="skel-row"><span class="skel skel-w-45"></span></div>
    <div class="skel-row"><span class="skel skel-w-52"></span></div>
</div>

<div id="projectsEmptyState" class="empty-state" hidden>
    <p class="empty-state-title">ยังไม่มีโปรเจค</p>
    <p class="empty-state-text">โปรเจคคือกลุ่มงานที่ทำร่วมกันหลายขั้น แต่ละโปรเจคมีบอร์ด 4 คอลัมน์ ทีม และแชทของตัวเอง เริ่มจากสร้างโปรเจคแรก</p>
    <button class="btn btn-primary" type="button" data-act="openCreateProjectModal">สร้างโปรเจคแรก</button>
</div>

<!-- The open project -->
<section id="kanbanBoardSection" class="sec" aria-labelledby="activeProjectTitle" hidden>
    <div class="sec-head">
        <h2><span class="proj-board-label">บอร์ด</span> <span id="activeProjectTitle"></span></h2>
        <div class="proj-board-actions">
            <button class="btn btn-sm" type="button" id="btnInviteMember" data-act="openInviteMemberModal" hidden>ทีม (<span id="memberCountBadge">1</span>)</button>
            <button class="btn btn-sm" type="button" id="btnEditProject" data-act="openEditProjectModal" hidden>แก้ไข</button>
            <button class="btn btn-sm btn-danger" type="button" id="btnDeleteProject" data-act="deleteActiveProject" hidden>ลบโปรเจค</button>
        </div>
    </div>

    <div class="kanban-board">
        <?php foreach ([
            ['todo', 'To Do', 'ต้องทำ'],
            ['inprogress', 'In Progress', 'กำลังทำ'],
            ['review', 'Review', 'รอตรวจ'],
            ['done', 'Done', 'เสร็จแล้ว'],
        ] as [$slug, $status, $label]): ?>
        <div class="kanban-column" id="col-<?= $slug ?>-wrap">
            <div class="kanban-column-header">
                <h3 class="kanban-column-title"><?= h($label) ?></h3>
                <span class="kanban-column-count" id="<?= $slug ?>-count">0</span>
            </div>
            <div class="kanban-cards-list" id="<?= $slug ?>-list" data-status="<?= h($status) ?>"></div>
            <div class="kanban-quick-add">
                <button class="btn btn-link btn-sm kanban-quick-add-btn" type="button" data-act="toggleQuickAddForm" data-args='["<?= h($status) ?>"]'>เพิ่มงานใน "<?= h($label) ?>"</button>
                <div class="kanban-quick-add-form" id="quickadd-<?= $slug ?>-form" hidden>
                    <label class="sr-only" for="quickadd-<?= $slug ?>-input">ชื่องาน</label>
                    <input type="text" class="form-control" id="quickadd-<?= $slug ?>-input" placeholder="ชื่องาน แล้วกด Enter" autocomplete="off"
                           data-act="handleQuickAddKey" data-args='["$event", "<?= h($status) ?>"]' data-on="keydown">
                    <div class="kanban-quick-add-actions">
                        <button class="btn btn-sm" type="button" data-act="toggleQuickAddForm" data-args='["<?= h($status) ?>", false]'>ยกเลิก</button>
                        <button class="btn btn-sm btn-primary" type="button" data-act="submitQuickAdd" data-args='["<?= h($status) ?>"]'>เพิ่ม</button>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>

<div class="proj-widgets">
    <section id="aiSummaryCardWrap" class="sec" aria-labelledby="insightTitle" hidden>
        <div class="sec-head"><h2 id="insightTitle">สรุปสถานะโปรเจค</h2></div>
        <p class="proj-insight" id="aiInsightText"></p>
        <p class="alert alert-warning" id="aiWarningBox" role="status" hidden></p>
        <p class="proj-note">คำนวณจากจำนวนงาน ความสำคัญ และวันส่งของโปรเจคนี้ ไม่ได้ใช้ AI</p>
    </section>

    <section id="analyticsWidgetCard" class="sec" aria-labelledby="progressTitle" hidden>
        <div class="sec-head"><h2 id="progressTitle">ความคืบหน้า</h2></div>
        <div class="facts proj-facts">
            <div class="fact"><div class="k">เสร็จแล้ว</div><div class="v" id="statCompletedTasks">0</div></div>
            <div class="fact"><div class="k">ยังค้างอยู่</div><div class="v" id="statRemainingTasks">0</div></div>
            <div class="fact"><div class="k">สำเร็จ</div><div class="v" id="statProductivity">0%</div></div>
        </div>
        <div class="proj-status-bar" id="projectStatusBar" role="img" aria-label="สัดส่วนงานแต่ละสถานะ"></div>
        <ul class="proj-legend" id="projectStatusLegend"></ul>
    </section>

    <section id="miniCalendarWidgetCard" class="sec" aria-labelledby="calendarMonthTitle">
        <div class="sec-head">
            <h2 id="calendarMonthTitle">เดือนนี้</h2>
            <div class="mini-cal-nav">
                <button class="icon-btn sm" type="button" data-act="navCalendar" data-args="[-1]" aria-label="เดือนก่อน">&lsaquo;</button>
                <button class="icon-btn sm" type="button" data-act="navCalendar" data-args="[1]" aria-label="เดือนถัดไป">&rsaquo;</button>
            </div>
        </div>
        <div class="mini-cal-grid" id="miniCalendarGrid"></div>
        <p class="proj-note">วันที่ขีดเส้นใต้มีกำหนดส่งโปรเจคหรืองาน</p>
    </section>

    <section id="activityWidgetCard" class="sec" aria-labelledby="activityTitle" hidden>
        <div class="sec-head"><h2 id="activityTitle">กิจกรรมล่าสุด</h2></div>
        <ul class="activity-feed-list" id="projectActivityList"></ul>
    </section>

    <section id="chatWidgetCard" class="sec" aria-labelledby="chatTitle" hidden>
        <div class="sec-head">
            <h2 id="chatTitle">แชทในโปรเจค</h2>
            <span class="chat-status-text" id="chatStatusText" role="status">กำลังเชื่อมต่อ</span>
        </div>
        <p id="guestRenameContainer" class="proj-note" hidden>
            <span id="lblGuestName">คุณ: …</span>
            <button class="btn btn-link btn-sm" type="button" data-act="changeGuestName">เปลี่ยนชื่อที่แสดง</button>
        </p>
        <div class="chat-messages-container" id="chatMessagesList" aria-live="polite"></div>
        <form class="chat-input-wrap" id="chatForm" data-act="sendChatMessage" novalidate>
            <label class="sr-only" for="chatMessageInput">ข้อความ</label>
            <input type="text" id="chatMessageInput" class="form-control" placeholder="พิมพ์ข้อความถึงทีม" autocomplete="off" maxlength="1000">
            <button type="submit" class="btn btn-primary">ส่ง</button>
        </form>
    </section>
</div>

<!-- New project -->
<div class="modal-backdrop" id="createProjectModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="createProjectTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="createProjectTitle">สร้างโปรเจค</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="createProjectForm" data-act="submitCreateProject" data-args='["$event"]' data-on="submit" novalidate>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="newProjName">ชื่อโปรเจค</label>
                    <input type="text" id="newProjName" class="form-control" name="name" maxlength="255" autocomplete="off" placeholder="เช่น ออกแบบเว็บไซต์ร้านกาแฟ">
                </div>
                <div class="form-group">
                    <label class="form-label" for="newProjDesc">คำอธิบายสั้นๆ</label>
                    <textarea id="newProjDesc" class="form-control" name="description" rows="3" placeholder="ขอบเขตงานคร่าวๆ"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="newProjPriority">ความสำคัญ</label>
                        <select id="newProjPriority" class="form-control" name="priority">
                            <option value="Low">ต่ำ</option>
                            <option value="Medium" selected>ปานกลาง</option>
                            <option value="High">สูง</option>
                            <option value="Critical">วิกฤต</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="newProjStatus">สถานะ</label>
                        <select id="newProjStatus" class="form-control" name="status">
                            <option value="Planning" selected>วางแผน</option>
                            <option value="In Progress">กำลังทำ</option>
                            <option value="Review">รอตรวจ</option>
                            <option value="Completed">เสร็จแล้ว</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="newProjDue">กำหนดส่ง</label>
                    <input type="date" id="newProjDue" class="form-control" name="due_date">
                </div>
                <p class="form-error" id="newProjError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal>ยกเลิก</button>
                <button type="submit" class="btn btn-primary">สร้างโปรเจค</button>
            </div>
        </form>
    </div>
</div>

<!-- Edit project -->
<div class="modal-backdrop" id="editProjectModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="editProjectTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="editProjectTitle">แก้ไขโปรเจค</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="editProjectForm" data-act="submitEditProject" data-args='["$event"]' data-on="submit" novalidate>
            <input type="hidden" id="editProjId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="editProjName">ชื่อโปรเจค</label>
                    <input type="text" id="editProjName" class="form-control" maxlength="255" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="editProjDesc">คำอธิบายสั้นๆ</label>
                    <textarea id="editProjDesc" class="form-control" rows="3"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="editProjPriority">ความสำคัญ</label>
                        <select id="editProjPriority" class="form-control">
                            <option value="Low">ต่ำ</option>
                            <option value="Medium">ปานกลาง</option>
                            <option value="High">สูง</option>
                            <option value="Critical">วิกฤต</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editProjStatus">สถานะ</label>
                        <select id="editProjStatus" class="form-control">
                            <option value="Planning">วางแผน</option>
                            <option value="In Progress">กำลังทำ</option>
                            <option value="Review">รอตรวจ</option>
                            <option value="Completed">เสร็จแล้ว</option>
                        </select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editProjDue">กำหนดส่ง</label>
                    <input type="date" id="editProjDue" class="form-control">
                </div>
                <p class="form-error" id="editProjError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal>ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึกโปรเจค</button>
            </div>
        </form>
    </div>
</div>

<!-- A task, with its checklist -->
<div class="modal-backdrop" id="editTaskModal" aria-hidden="true">
    <div class="modal modal-task" role="dialog" aria-labelledby="editTaskTitleHeading">
        <div class="modal-header">
            <h2 class="modal-title" id="editTaskTitleHeading">แก้ไขงาน</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="editTaskForm" data-act="submitEditTask" data-args='["$event"]' data-on="submit" novalidate>
            <input type="hidden" id="editTaskId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="editTaskTitle">ชื่องาน</label>
                    <input type="text" id="editTaskTitle" class="form-control" maxlength="255" autocomplete="off">
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="editTaskStatus">อยู่ในคอลัมน์</label>
                        <select id="editTaskStatus" class="form-control">
                            <option value="To Do">ต้องทำ</option>
                            <option value="In Progress">กำลังทำ</option>
                            <option value="Review">รอตรวจ</option>
                            <option value="Done">เสร็จแล้ว</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editTaskPriority">ความสำคัญ</label>
                        <select id="editTaskPriority" class="form-control">
                            <option value="Low">ต่ำ</option>
                            <option value="Medium">ปานกลาง</option>
                            <option value="High">สูง</option>
                            <option value="Critical">วิกฤต</option>
                        </select>
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="editTaskCategory">หมวดหรือแท็ก</label>
                        <input type="text" id="editTaskCategory" class="form-control" maxlength="100" placeholder="เช่น ดีไซน์, โค้ด, เนื้อหา" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="editTaskAssignee">ผู้รับผิดชอบ</label>
                        <select id="editTaskAssignee" class="form-control"><option value="">ยังไม่ระบุ</option></select>
                    </div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="editTaskDue">กำหนดส่ง</label>
                    <input type="date" id="editTaskDue" class="form-control">
                </div>

                <div class="modal-checklist-container">
                    <div class="checklist-header">
                        <span id="checklistHeading">เช็กลิสต์ย่อย</span>
                        <span id="checklistPercentageLabel">0% เสร็จ</span>
                    </div>
                    <div class="progress" aria-hidden="true"><div class="progress-bar" id="checklistProgressFill"></div></div>
                    <div class="checklist-list" id="modalChecklistList" role="group" aria-labelledby="checklistHeading"></div>
                    <div class="checklist-add">
                        <label class="sr-only" for="newChecklistItemInput">รายการเช็กลิสต์ใหม่</label>
                        <input type="text" class="form-control" id="newChecklistItemInput" placeholder="เพิ่มรายการย่อยในงานนี้" autocomplete="off">
                        <button type="button" class="btn btn-sm" data-act="addChecklistItem">เพิ่ม</button>
                    </div>
                </div>
                <p class="form-error" id="taskError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal>ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึกงาน</button>
            </div>
        </form>
    </div>
</div>

<!-- The team and the public link -->
<div class="modal-backdrop" id="inviteMemberModal" aria-hidden="true">
    <div class="modal" role="dialog" aria-labelledby="teamTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="teamTitle">ทีมของโปรเจค</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <div class="modal-body">
            <h3 class="subhead">สมาชิก</h3>
            <ul class="members-list-wrapper ruled-list" id="projectMembersList"></ul>

            <form id="inviteMemberForm" data-act="submitInviteMember" data-args='["$event"]' data-on="submit" novalidate hidden>
                <h3 class="subhead">เชิญคนเข้าทีม</h3>
                <div class="form-group">
                    <label class="form-label" for="inviteSearchInput">ชื่อผู้ใช้หรืออีเมล</label>
                    <input type="text" id="inviteSearchInput" class="form-control" placeholder="ผู้ที่มีบัญชีในระบบนี้" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="inviteRoleSelect">สิทธิ์</label>
                    <select id="inviteRoleSelect" class="form-control">
                        <option value="Editor" selected>แก้ไขได้ (เพิ่มและแก้งาน)</option>
                        <option value="Viewer">ดูได้อย่างเดียว (ดูบอร์ดและแชท)</option>
                    </select>
                </div>
                <p class="form-error" id="inviteError" role="alert" hidden></p>
                <button type="submit" class="btn btn-primary">เชิญเข้าทีม</button>
            </form>

            <div id="publicShareSection" hidden>
                <h3 class="subhead">ลิงก์สำหรับคนนอกทีม</h3>
                <div class="proj-share-row">
                    <label class="proj-share-label" for="shareLinkToggle">เปิดให้เข้าผ่านลิงก์โดยไม่ต้องมีบัญชี</label>
                    <label class="switch">
                        <input type="checkbox" id="shareLinkToggle" data-act="togglePublicShare" data-on="change">
                        <span class="slider"></span>
                    </label>
                </div>
                <div id="shareLinkDetails" hidden>
                    <div class="form-group">
                        <label class="form-label" for="shareLinkRole">สิทธิ์ของคนที่เข้าผ่านลิงก์</label>
                        <select id="shareLinkRole" class="form-control" data-act="updateShareRole" data-on="change">
                            <option value="Viewer">ดูอย่างเดียว (ดูบอร์ดและแชท)</option>
                            <option value="Editor">แก้ไขได้ (จัดการงานและแชท)</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="shareLinkUrl">ลิงก์</label>
                        <div class="proj-share-url">
                            <input type="text" id="shareLinkUrl" class="form-control" readonly>
                            <button type="button" class="btn btn-sm" data-act="copyShareUrl">คัดลอก</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- A guest's display name -->
<div class="modal-backdrop" id="guestNameModal" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="guestNameTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="guestNameTitle">ชื่อที่แสดงในแชท</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="guestNameForm" data-act="saveGuestName" data-args='["$event"]' data-on="submit" novalidate>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="guestNameInput">ชื่อของคุณ</label>
                    <input type="text" id="guestNameInput" class="form-control" maxlength="50" autocomplete="off">
                </div>
                <p class="form-error" id="guestNameError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal>ยกเลิก</button>
                <button type="submit" class="btn btn-primary">บันทึกชื่อ</button>
            </div>
        </form>
    </div>
</div>
