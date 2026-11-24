<?php
// =====================================================
// views/projects/index.php — projects
//
// The projects as ruled rows with their progress; the open one as a board whose
// columns come from the project's type, with the people, the figures, a calendar,
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
    <?php if (!$isGuest): /* a visitor on a public link has no account to create a project in */ ?>
    <div class="page-head-actions">
        <button class="btn btn-primary" type="button" data-act="openCreateProjectModal"><svg class="icon" aria-hidden="true"><use href="#i-plus"/></svg>สร้างโปรเจค</button>
    </div>
    <?php endif; ?>
</div>

<div class="proj-filters">
    <label class="sr-only" for="projectSearch">ค้นหาโปรเจค</label>
    <input type="search" id="projectSearch" class="form-control proj-search" placeholder="ค้นหาโปรเจค" autocomplete="off" data-act="filterProjects" data-on="input">
    <label class="sr-only" for="projectTypeFilter">ประเภท</label>
    <select id="projectTypeFilter" class="form-control" data-act="filterProjects" data-on="change">
        <option value="">ทุกประเภท</option>
        <option value="general">ทั่วไป</option>
        <option value="software">พัฒนาซอฟต์แวร์</option>
        <option value="marketing">การตลาดและคอนเทนต์</option>
        <option value="finance">บัญชีและการเงิน</option>
    </select>
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
    <p class="empty-state-text">โปรเจคคือกลุ่มงานที่ทำร่วมกันหลายขั้น แต่ละโปรเจคมีบอร์ด ทีม และแชทของตัวเอง เริ่มจากสร้างโปรเจคแรก</p>
    <?php if (!$isGuest): ?>
    <button class="btn btn-primary" type="button" data-act="openCreateProjectModal">สร้างโปรเจคแรก</button>
    <?php endif; ?>
</div>

<!-- The open project -->
<section id="kanbanBoardSection" class="sec" aria-labelledby="activeProjectTitle" hidden>
    <div class="proj-head" id="activeProjectHead">
        <span class="type-ear" id="activeProjectEar"></span>
        <div class="proj-head-row">
            <div>
                <h2 class="proj-head-title" id="activeProjectTitle"></h2>
                <span class="me">คุณเป็น <b id="activeProjectRole"></b></span>
            </div>
            <div class="proj-board-actions">
                <button class="btn btn-sm" type="button" id="btnInviteMember" data-act="openInviteMemberModal" hidden>ทีม (<span id="memberCountBadge">1</span>)</button>
                <button class="btn btn-sm" type="button" id="btnEditProject" data-act="openEditProjectModal" hidden>แก้ไข</button>
                <button class="btn btn-sm btn-danger" type="button" id="btnDeleteProject" data-act="deleteActiveProject" hidden>ลบโปรเจค</button>
            </div>
        </div>
    </div>

    <div class="kanban-board" id="kanbanBoard"></div>
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
        <div class="proj-status-bar" id="projectStatusBar" role="img" aria-label="สัดส่วนงานแต่ละคอลัมน์"></div>
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

<!-- New project: step 1 the type, step 2 the details -->
<div class="modal-backdrop" id="createProjectModal" aria-hidden="true">
    <div class="modal modal-wide" role="dialog" aria-labelledby="createProjectTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="createProjectTitle">สร้างโปรเจค</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="createProjectForm" data-act="submitCreateProject" data-args='["$event"]' data-on="submit" novalidate>
            <div class="modal-body">
                <div id="createProjectStep1">
                    <p class="step-label">ขั้น 1 จาก 2 · โปรเจคนี้เป็นงานแบบไหน</p>
                    <fieldset class="type-pick">
                        <legend class="sr-only">ประเภทโปรเจค</legend>
                        <label class="type-card" data-ptype="general">
                            <input type="radio" name="ptype" value="general" checked>
                            <span class="type-ear">ทั่วไป</span>
                            <span class="sheet">
                                <span class="thumb t-board"><span><i></i><i></i><i></i></span><span><i></i><i></i></span><span><i></i></span><span><i></i><i></i></span></span>
                                <span><h3>งานทั่วไป</h3><p>บอร์ดลากงานข้ามคอลัมน์ เหมาะกับงานบ้าน งานอีเวนต์ หรืองานที่ไม่เข้าหมวดไหน</p>
                                <span class="defaults">ได้คอลัมน์: <b>ต้องทำ · กำลังทำ · รอตรวจ · เสร็จแล้ว</b></span></span>
                            </span>
                        </label>
                        <label class="type-card" data-ptype="software">
                            <input type="radio" name="ptype" value="software">
                            <span class="type-ear">ซอฟต์แวร์</span>
                            <span class="sheet">
                                <span class="thumb t-backlog"><span>DF-12<i></i><i></i></span><span>DF-13<i></i><i></i></span><span>DF-14<i></i><i></i></span><span>DF-15<i></i><i></i></span><span>DF-16<i></i><i></i></span></span>
                                <span><h3>พัฒนาซอฟต์แวร์</h3><p>เลขงานอัตโนมัติ คลังงาน รอบงาน เวอร์ชัน และบั๊ก สำหรับทำแอปหรือเว็บ</p>
                                <span class="defaults">ได้คอลัมน์: <b>ต้องทำ · กำลังทำ · รอรีวิว · ทดสอบ · เสร็จ</b></span></span>
                            </span>
                        </label>
                        <label class="type-card" data-ptype="marketing">
                            <input type="radio" name="ptype" value="marketing">
                            <span class="type-ear">การตลาด</span>
                            <span class="sheet">
                                <span class="thumb t-cal"><i></i><i class="on"></i><i></i><i></i><i class="on"></i><i></i><i></i><i></i><i></i><i class="on"></i><i></i><i></i><i></i><i class="on"></i><i class="on"></i><i></i><i></i><i></i><i class="on"></i><i></i><i></i></span>
                                <span><h3>การตลาดและคอนเทนต์</h3><p>ปฏิทินโพสต์ทุกช่องทาง ส่งโพสต์ให้อนุมัติ และแคมเปญเทียบงบ</p>
                                <span class="defaults">ได้คอลัมน์: <b>ไอเดีย · ร่าง · รอตรวจ · นัดโพสต์ · โพสต์แล้ว</b></span></span>
                            </span>
                        </label>
                        <label class="type-card" data-ptype="finance">
                            <input type="radio" name="ptype" value="finance">
                            <span class="type-ear">บัญชี</span>
                            <span class="sheet">
                                <span class="thumb t-ledger"><span>4,500.00</span><span>12,000.00</span><span>850.00</span><span>3,200.00</span><span>20,550.00</span></span>
                                <span><h3>บัญชีและการเงิน</h3><p>งบ ค่าใช้จ่ายพร้อมใบเสร็จและการอนุมัติ ใบเสนอราคา ใบแจ้งหนี้</p>
                                <span class="defaults">งานใช้คอลัมน์แบบทั่วไป ส่วนค่าใช้จ่ายมีขั้นของตัวเอง (มาในเฟสบัญชี)</span></span>
                            </span>
                        </label>
                    </fieldset>
                </div>
                <div id="createProjectStep2" hidden>
                    <p class="step-label">ขั้น 2 จาก 2 · <span id="createStepTypeName">ทั่วไป</span> · <button type="button" class="btn-link" data-act="createStepBack">เปลี่ยนประเภท</button></p>
                    <div class="form-group">
                        <label class="form-label" for="newProjName">ชื่อโปรเจค</label>
                        <input type="text" id="newProjName" class="form-control" name="name" maxlength="255" autocomplete="off" placeholder="เช่น ออกแบบเว็บไซต์ร้านกาแฟ">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="newProjDesc">คำอธิบายสั้นๆ <span class="text-muted">(ไม่บังคับ)</span></label>
                        <textarea id="newProjDesc" class="form-control" name="description" rows="3" placeholder="ขอบเขตงานคร่าวๆ"></textarea>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="newProjDue">กำหนดส่ง <span class="text-muted">(ไม่บังคับ)</span></label>
                            <input type="date" id="newProjDue" class="form-control" name="due_date">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="newProjPriority">ความสำคัญ</label>
                            <select id="newProjPriority" class="form-control" name="priority">
                                <option value="Low">ต่ำ</option>
                                <option value="Medium" selected>ปานกลาง</option>
                                <option value="High">สูง</option>
                                <option value="Critical">วิกฤต</option>
                            </select>
                        </div>
                    </div>
                    <p class="form-hint">ชวนคนเข้าทีมได้หลังสร้าง ใช้คนเดียวก็ได้ทุกอย่าง</p>
                    <p class="form-error" id="newProjError" role="alert" hidden></p>
                </div>
            </div>
            <div class="modal-footer" id="createProjectFooter1">
                <button type="button" class="btn" data-close-modal>ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="createProjectNext" data-act="createStepNext">ถัดไป</button>
            </div>
            <div class="modal-footer" id="createProjectFooter2" hidden>
                <button type="button" class="btn" data-act="createStepBack">ย้อนกลับ</button>
                <button type="submit" class="btn btn-primary" id="createProjectSubmit">สร้างโปรเจค</button>
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
                        <label class="form-label" for="editTaskColumn">คอลัมน์</label>
                        <select id="editTaskColumn" class="form-control"></select>
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
