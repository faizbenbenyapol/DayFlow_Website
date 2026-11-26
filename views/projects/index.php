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
                <button class="btn btn-sm" type="button" id="btnEditProject" data-act="openEditProjectModal" hidden>แก้ไข</button>
                <button class="btn btn-sm btn-danger" type="button" id="btnDeleteProject" data-act="deleteActiveProject" hidden>ลบโปรเจค</button>
            </div>
        </div>
    </div>

    <!-- One filter for the board, the list and the calendar; it stays while switching (projects-views.js) -->
    <div class="view-filters" id="taskFilters">
        <input type="search" id="taskFilterQ" class="form-control grow" placeholder="ค้นหางาน" aria-label="ค้นหางาน" autocomplete="off" data-act="setTaskFilter" data-args='["q", "$value"]' data-on="input">
        <select id="taskFilterWho" class="form-control" aria-label="ผู้รับผิดชอบ" data-act="setTaskFilter" data-args='["who", "$value"]' data-on="change"></select>
        <select id="taskFilterGrp" class="form-control" aria-label="สถานะ" data-act="setTaskFilter" data-args='["grp", "$value"]' data-on="change">
            <option value="">ทุกสถานะ</option>
            <option value="todo">ยังไม่เริ่ม</option>
            <option value="doing">กำลังทำ</option>
            <option value="done">เสร็จ</option>
        </select>
        <select id="taskFilterDue" class="form-control" aria-label="ครบกำหนด" data-act="setTaskFilter" data-args='["due", "$value"]' data-on="change">
            <option value="">ทุกวันส่ง</option>
            <option value="over">เลยกำหนด</option>
            <option value="week">7 วันข้างหน้า</option>
            <option value="none">ไม่มีวันส่ง</option>
        </select>
        <select id="taskFilterKind" class="form-control" aria-label="ชนิดงาน" data-act="setTaskFilter" data-args='["kind", "$value"]' data-on="change" hidden>
            <option value="">ทุกชนิด</option>
            <option value="epic">งานใหญ่</option>
            <option value="story">เรื่องผู้ใช้</option>
            <option value="task">งาน</option>
            <option value="bug">บั๊ก</option>
            <option value="sub">งานย่อย</option>
        </select>
        <select id="taskFilterEpic" class="form-control" aria-label="งานใหญ่" data-act="setTaskFilter" data-args='["epic", "$value"]' data-on="change" hidden></select>
        <span class="view-field-filters" id="taskFieldFilters"></span>
        <span class="view-filter-state" id="taskFilterState" role="status" hidden></span>
        <button class="btn btn-link btn-sm" type="button" id="taskFilterClear" data-act="clearTaskFilters" hidden>ล้างตัวกรอง</button>
    </div>

    <div class="tabs" role="tablist" aria-label="มุมมองของโปรเจค" id="projectTabs">
        <button class="tab" role="tab" type="button" id="tabBoard" aria-selected="true" aria-controls="boardPanel" data-act="showProjectTab" data-args='["board"]'>บอร์ด</button>
        <button class="tab" role="tab" type="button" id="tabBacklog" aria-selected="false" aria-controls="backlogPanel" data-act="showProjectTab" data-args='["backlog"]' hidden>คลังงาน</button>
        <button class="tab" role="tab" type="button" id="tabList" aria-selected="false" aria-controls="listPanel" data-act="showProjectTab" data-args='["list"]'>รายการ</button>
        <button class="tab" role="tab" type="button" id="tabCalendar" aria-selected="false" aria-controls="calendarPanel" data-act="showProjectTab" data-args='["calendar"]'>ปฏิทิน</button>
        <button class="tab" role="tab" type="button" id="tabOverview" aria-selected="false" aria-controls="overviewPanel" data-act="showProjectTab" data-args='["overview"]'>ภาพรวม</button>
        <?php if (!$isGuest): /* a share-link guest has no settings to change */ ?>
        <button class="tab" role="tab" type="button" id="tabSettings" aria-selected="false" aria-controls="settingsPanel" data-act="showProjectTab" data-args='["settings"]'>ตั้งค่า</button>
        <?php endif; ?>
    </div>

    <div id="boardPanel" role="tabpanel" aria-labelledby="tabBoard">
        <div class="sprint-bar" id="boardSprintBar" hidden></div>
        <p class="proj-note" id="boardFilterNote" hidden>กรองอยู่ ลากการ์ดไม่ได้จนกว่าจะล้างตัวกรอง (เปลี่ยนคอลัมน์ในรายละเอียดงานได้)</p>
        <div class="empty-state" id="boardSprintEmpty" hidden>
            <p class="empty-state-title">ยังไม่มีรอบที่กำลังทำ</p>
            <p class="empty-state-text">เริ่มรอบจากแท็บคลังงาน แล้วบอร์ดจะแสดงเฉพาะงานในรอบนั้น</p>
            <button class="btn btn-primary" type="button" data-act="showProjectTab" data-args='["backlog"]'>ไปที่คลังงาน</button>
        </div>
        <div class="kanban-board" id="kanbanBoard"></div>
    </div>

    <!-- คลังงาน + รอบงาน: แสดงเฉพาะโปรเจคซอฟต์แวร์ที่เปิดใช้รอบงาน (projects-sprints.js) -->
    <div class="proj-view" id="backlogPanel" role="tabpanel" aria-labelledby="tabBacklog" hidden>
        <p class="proj-note" id="backlogFilterNote" hidden>กรองอยู่ ลากงานไม่ได้จนกว่าจะล้างตัวกรอง (เปลี่ยนรอบงานในรายละเอียดงานได้)</p>
        <div class="bl-toolbar">
            <button class="btn btn-sm" type="button" id="btnAddSprint" data-act="createSprint" hidden>+ สร้างรอบงาน</button>
        </div>
        <div class="bl-split" id="backlogBlocks"></div>
    </div>

    <div class="proj-view" id="listPanel" role="tabpanel" aria-labelledby="tabList" hidden>
        <div class="task-list-head">
            <label class="text-sm" for="taskListGroup">จัดกลุ่มตาม</label>
            <select id="taskListGroup" class="form-control" data-act="setTaskFilter" data-args='["group", "$value"]' data-on="change">
                <option value="column">คอลัมน์</option>
                <option value="who">ผู้รับผิดชอบ</option>
                <option value="priority">ความสำคัญ</option>
                <option value="">ไม่จัดกลุ่ม</option>
            </select>
        </div>
        <div class="task-table-wrap">
            <table class="task-table" id="taskListTable">
                <thead><tr>
                    <th scope="col" data-sort="title"><button class="th-sort" type="button" data-act="sortTaskList" data-args='["title"]'>งาน</button></th>
                    <th scope="col" data-sort="column" class="c-col"><button class="th-sort" type="button" data-act="sortTaskList" data-args='["column"]'>คอลัมน์</button></th>
                    <th scope="col" data-sort="who"><button class="th-sort" type="button" data-act="sortTaskList" data-args='["who"]'>ผู้รับผิดชอบ</button></th>
                    <th scope="col" data-sort="due"><button class="th-sort" type="button" data-act="sortTaskList" data-args='["due"]'>ครบกำหนด</button></th>
                    <th scope="col" data-sort="priority"><button class="th-sort" type="button" data-act="sortTaskList" data-args='["priority"]'>ความสำคัญ</button></th>
                </tr></thead>
                <tbody id="taskListBody"></tbody>
            </table>
        </div>
    </div>

    <div class="proj-view" id="calendarPanel" role="tabpanel" aria-labelledby="tabCalendar" hidden>
        <div class="cal-wrap">
            <div>
                <div class="cal-head">
                    <h3 id="taskCalTitle"></h3>
                    <span class="cal-nav">
                        <button class="btn btn-sm" type="button" data-act="navTaskCalendar" data-args="[-1]" aria-label="เดือนก่อน">&lsaquo;</button>
                        <button class="btn btn-sm" type="button" data-act="taskCalendarToday">วันนี้</button>
                        <button class="btn btn-sm" type="button" data-act="navTaskCalendar" data-args="[1]" aria-label="เดือนถัดไป">&rsaquo;</button>
                    </span>
                </div>
                <div class="cal-grid-wrap"><div class="cal-grid" id="taskCalGrid"></div></div>
                <div class="agenda" id="taskCalAgenda"></div>
            </div>
            <aside class="unscheduled" id="taskCalUnscheduled" aria-label="งานที่ยังไม่ลงวัน"></aside>
        </div>
    </div>

    <div class="proj-view" id="overviewPanel" role="tabpanel" aria-labelledby="tabOverview" hidden>
        <!-- กราฟรอบงาน: แสดงเฉพาะโปรเจคซอฟต์แวร์ที่เปิดรอบงาน และไม่ใช่หน้าลิงก์แชร์ (projects-reports.js) -->
        <section id="sprintReports" aria-label="กราฟรอบงานและความเร็วทีม" hidden>
            <div class="rep-grid">
                <div class="chart-card">
                    <div class="chart-card-head">
                        <h3 id="burndownTitle">งานคงเหลือรายวัน</h3>
                        <label class="chart-pick" id="reportSprintPick" for="reportSprintSelect" hidden>รอบ
                            <select id="reportSprintSelect" class="form-control" data-act="selectReportSprint" data-args='["$value"]' data-on="change"></select>
                        </label>
                    </div>
                    <p class="sub">แต้มที่ยังไม่เสร็จ ณ สิ้นวัน เทียบกับเส้นที่ควรเป็น</p>
                    <svg class="chart" id="burndownChart" viewBox="0 0 460 240" role="img" aria-labelledby="burndownDesc"><desc id="burndownDesc">กราฟงานคงเหลือ</desc></svg>
                    <div class="legend" id="burndownLegend">
                        <span><i class="sw-actual" aria-hidden="true"></i>จริง</span>
                        <span><i class="sw-ideal" aria-hidden="true"></i>เส้นที่ควรเป็น</span>
                        <span>วันหยุดนับด้วย (เส้นแบนได้)</span>
                    </div>
                    <p class="proj-note" id="burndownEmpty" role="status" hidden></p>
                    <details id="burndownDetails"><summary>ดูเป็นตาราง</summary><table class="data-table" id="burndownTable"></table></details>
                </div>
                <div class="chart-card">
                    <h3 id="velocityTitle">ความเร็วทีม</h3>
                    <p class="sub" id="velocitySub">แต้มที่วางแผนตอนเริ่มรอบ เทียบกับที่เสร็จ</p>
                    <svg class="chart" id="velocityChart" viewBox="0 0 320 240" role="img" aria-labelledby="velocityDesc"><desc id="velocityDesc">กราฟความเร็วทีม</desc></svg>
                    <div class="legend" id="velocityLegend">
                        <span><i class="sw-plan" aria-hidden="true"></i>วางแผน</span>
                        <span><i class="sw-done" aria-hidden="true"></i>เสร็จ</span>
                        <span><i class="sw-avg" aria-hidden="true"></i>เฉลี่ย</span>
                    </div>
                    <p class="proj-note" id="velocityEmpty" role="status" hidden></p>
                    <details id="velocityDetails"><summary>ดูเป็นตาราง</summary><table class="data-table" id="velocityTable"></table></details>
                </div>
            </div>
        </section>
        <div class="ov-grid" id="overviewGrid">
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
    </div>

    <div id="settingsPanel" role="tabpanel" aria-labelledby="tabSettings" hidden>
        <nav class="subnav proj-subnav" aria-label="ตั้งค่าโปรเจค">
            <button class="subnav-link" type="button" id="settingsNavTeam" aria-current="page" data-act="showSettingsSection" data-args='["team"]'>ทีมและสิทธิ์</button>
            <button class="subnav-link" type="button" id="settingsNavColumns" data-act="showSettingsSection" data-args='["columns"]'>คอลัมน์</button>
            <button class="subnav-link" type="button" id="settingsNavFields" data-act="showSettingsSection" data-args='["fields"]'>ช่องข้อมูล</button>
            <button class="subnav-link" type="button" id="settingsNavType" data-act="showSettingsSection" data-args='["type"]'>ประเภท</button>
            <button class="subnav-link" type="button" id="settingsNavSoftware" data-act="showSettingsSection" data-args='["software"]' hidden>ซอฟต์แวร์</button>
        </nav>

        <!-- ทีมและสิทธิ์ -->
        <div class="proj-settings-pane" id="settingsTeam">
            <div class="sec">
                <div class="sec-head">
                    <h2>คนในโปรเจค</h2>
                    <button class="btn btn-sm btn-primary" type="button" id="btnFocusInvite" data-act="focusInviteForm" hidden>ชวนคนเข้าทีม</button>
                </div>
                <div class="proj-members" id="projectMembersList"></div>

                <div class="empty-state" id="teamSoloEmpty" hidden>
                    <p class="empty-state-title">ตอนนี้มีคุณคนเดียว</p>
                    <p class="empty-state-text">ทุกอย่างใช้ได้ครบโดยไม่ต้องมีทีม ปุ่ม "อนุมัติ" คุณกดของตัวเองได้ ช่องผู้รับผิดชอบเว้นว่างได้ ชวนคนเข้ามาเมื่อพร้อม</p>
                    <button class="btn btn-primary" type="button" data-act="focusInviteForm">ชวนคนเข้าทีม</button>
                </div>

                <form id="inviteMemberForm" class="proj-invite" data-act="submitInviteMember" data-args='["$event"]' data-on="submit" novalidate hidden>
                    <h3 class="subhead">เชิญคนเข้าทีม</h3>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="inviteSearchInput">ชื่อผู้ใช้หรืออีเมล</label>
                            <input type="text" id="inviteSearchInput" class="form-control" placeholder="ผู้ที่มีบัญชีในระบบนี้" autocomplete="off">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="inviteRoleSelect">สิทธิ์</label>
                            <select id="inviteRoleSelect" class="form-control"></select>
                        </div>
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

            <div class="sec">
                <div class="sec-head"><h2>แต่ละบทบาททำอะไรได้</h2></div>
                <div class="perm-wrap">
                    <table class="perm">
                        <thead>
                            <tr>
                                <th scope="col">ทำอะไร</th>
                                <th scope="col" data-role-col="owner">เจ้าของ</th>
                                <th scope="col" data-role-col="admin">ผู้ดูแล</th>
                                <th scope="col" data-role-col="editor">สมาชิก</th>
                                <th scope="col" data-role-col="viewer">ดูอย่างเดียว</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td>ดูงาน ความคืบหน้า แชท</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td></tr>
                            <tr><td>สร้าง แก้ ลากงาน เขียนความเห็น</td><td class="y">✓</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td></tr>
                            <tr id="permSprintsRow" hidden><td>สร้าง เริ่ม ปิดรอบงาน</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td><td class="n">—</td></tr>
                            <tr><td>ตั้งคอลัมน์ ชวนคน เอาคนออก ลิงก์แชร์</td><td class="y">✓</td><td class="y">✓</td><td class="n">—</td><td class="n">—</td></tr>
                            <tr><td>ตั้งผู้ดูแล เปลี่ยนประเภท ลบโปรเจค</td><td class="y">✓</td><td class="n">—</td><td class="n">—</td><td class="n">—</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- คอลัมน์ -->
        <div class="proj-settings-pane" id="settingsColumns" hidden>
            <div id="columnsEditor"></div>
            <p class="alert alert-warning" id="columnsDenied" role="alert" hidden>คอลัมน์ตั้งได้เฉพาะเจ้าของและผู้ดูแล</p>
        </div>

        <!-- ช่องข้อมูลเพิ่มเติม (projects-fields.js) -->
        <div class="proj-settings-pane" id="settingsFields" hidden>
            <div id="fieldsEditor"></div>
            <p class="alert alert-warning" id="fieldsDenied" role="alert" hidden>ช่องข้อมูลตั้งได้เฉพาะเจ้าของและผู้ดูแล</p>
            <form class="sec" id="fieldAddForm" data-act="submitAddField" data-args='["$event"]' data-on="submit" novalidate>
                <div class="sec-head"><h2>เพิ่มช่อง</h2></div>
                <div class="form-row">
                    <div class="form-group">
                        <label class="form-label" for="fieldName">ชื่อช่อง</label>
                        <input type="text" id="fieldName" class="form-control" maxlength="60" placeholder="เช่น ลูกค้า" autocomplete="off">
                    </div>
                    <div class="form-group">
                        <label class="form-label" for="fieldKind">ชนิด</label>
                        <select id="fieldKind" class="form-control" data-act="toggleFieldOptions" data-args='["$value"]' data-on="change">
                            <option value="text">ข้อความ</option>
                            <option value="number">ตัวเลข</option>
                            <option value="date">วันที่</option>
                            <option value="select">ตัวเลือก</option>
                            <option value="person">คน</option>
                            <option value="check">ติ๊ก (ใช่/ไม่ใช่)</option>
                        </select>
                    </div>
                </div>
                <div class="form-group" id="fieldOptionsGroup" hidden>
                    <label class="form-label" for="fieldOptions">ตัวเลือก (บรรทัดละหนึ่ง)</label>
                    <textarea id="fieldOptions" class="form-control" rows="3"></textarea>
                </div>
                <p class="form-hint">ชนิดเปลี่ยนไม่ได้หลังเพิ่ม ลบช่องแล้วค่าที่กรอกไว้ในทุกงานจะหายไปด้วย ระบบจะถามก่อนลบ</p>
                <p class="form-error" id="fieldError" role="alert" hidden></p>
                <p class="proj-note" id="fieldsFull" hidden>ครบ 10 ช่องแล้ว ลบช่องที่ไม่ใช้ก่อนจึงเพิ่มได้</p>
                <button type="submit" class="btn btn-primary" id="fieldAddSubmit">เพิ่มช่อง</button>
            </form>
        </div>

        <!-- ประเภท -->
        <div class="proj-settings-pane" id="settingsType" hidden>
            <div class="sec">
                <div class="sec-head"><h2>ประเภทของโปรเจคนี้</h2></div>
                <div class="type-now" id="typeCurrentWrap">
                    <span class="type-ear" id="typeCurrentEar"></span>
                </div>
                <div id="typeChangeOwner" hidden>
                    <p class="proj-note">เปลี่ยนเป็น</p>
                    <div class="proj-type-buttons">
                        <button class="btn" type="button" data-type-button="general" data-act="changeProjectType" data-args='["general"]'>ทั่วไป</button>
                        <button class="btn" type="button" data-type-button="software" data-act="changeProjectType" data-args='["software"]'>พัฒนาซอฟต์แวร์</button>
                        <button class="btn" type="button" data-type-button="marketing" data-act="changeProjectType" data-args='["marketing"]'>การตลาดและคอนเทนต์</button>
                        <button class="btn" type="button" data-type-button="finance" data-act="changeProjectType" data-args='["finance"]'>บัญชีและการเงิน</button>
                    </div>
                </div>
                <p class="alert alert-warning" id="typeDenied" role="alert" hidden>เปลี่ยนประเภทได้เฉพาะเจ้าของ</p>
            </div>
        </div>

        <!-- ซอฟต์แวร์: ตัวย่อเลขงานและชุดแต้ม (เห็นเฉพาะโปรเจคซอฟต์แวร์) -->
        <div class="proj-settings-pane" id="settingsSoftware" hidden>
            <div class="sec">
                <div class="sec-head"><h2>ซอฟต์แวร์</h2></div>
                <div class="set-row">
                    <div>
                        <h4>ตัวย่อเลขงาน</h4>
                        <p>ตัวอักษรอังกฤษ 2–6 ตัว</p>
                    </div>
                    <div>
                        <label class="form-label" for="softwareKeyPrefix">ตัวย่อเลขงาน</label>
                        <div class="software-prefix-row">
                            <input type="text" id="softwareKeyPrefix" class="form-control" maxlength="6" autocomplete="off"
                                   data-act="updateSoftwarePrefixPreview" data-on="input" aria-describedby="softwarePrefixPreview">
                            <button type="button" class="btn btn-sm btn-primary" id="softwarePrefixSave" data-act="saveSoftwarePrefix">บันทึก</button>
                        </div>
                        <p class="proj-note" id="softwarePrefixPreview">งานถัดไปจะเป็น <span class="prefix-demo" id="softwarePrefixDemo">-</span></p>
                    </div>
                </div>
                <div class="set-row">
                    <div>
                        <h4>ทำงานเป็นรอบ (sprint)</h4>
                        <p>ปิดได้ถ้าทำงานคนเดียวแบบไหลต่อเนื่อง</p>
                    </div>
                    <div>
                        <label class="switch-row">
                            <span class="switch">
                                <input type="checkbox" id="useSprintsToggle" aria-label="ใช้รอบงาน"
                                       data-act="saveUseSprints" data-args='["$checked"]' data-on="change">
                                <span class="slider"></span>
                            </span>
                            <span>ใช้รอบงาน</span>
                        </label>
                        <p>เปิด: บอร์ดแสดงเฉพาะงานในรอบที่กำลังทำ งานอื่นอยู่ในแท็บคลังงาน · ปิด: บอร์ดแสดงทุกงานเหมือนเดิม ไม่มีกราฟงานคงเหลือ (แต้ม เลขงาน เวอร์ชัน แผนงานยังใช้ได้)</p>
                    </div>
                </div>
                <div class="set-row">
                    <div>
                        <h4>ชุดแต้ม</h4>
                        <p>ตัวเลือกในช่องแต้มของงาน</p>
                    </div>
                    <div>
                        <label class="sr-only" for="softwarePointScale">ชุดแต้ม</label>
                        <select id="softwarePointScale" class="form-control" data-act="saveSoftwarePointScale" data-args='["$value"]' data-on="change">
                            <option value="fib">ฟีโบนัชชี 1 2 3 5 8 13 21</option>
                            <option value="ten">1–10</option>
                        </select>
                    </div>
                </div>
                <p class="alert alert-warning" id="softwareDenied" role="alert" hidden>ตัวย่อเลขงานและชุดแต้มตั้งได้เฉพาะเจ้าของและผู้ดูแล</p>
            </div>
        </div>
    </div>
</section>

<div class="proj-widgets">
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
<!-- รายละเอียดงาน: แผงด้านขวา (mockup P0b แบบ A) บนมือถือเต็มจอ เปิดตรงได้ด้วย ?task=<id> -->
<div class="modal-backdrop task-panel" id="editTaskModal" aria-hidden="true">
    <div class="modal modal-task" role="dialog" aria-labelledby="editTaskTitleHeading">
        <div class="modal-header">
            <h2 class="modal-title" id="editTaskTitleHeading">รายละเอียดงาน <span class="key" id="editTaskKey" hidden></span></h2>
            <button class="icon-btn" type="button" data-act="copyTaskLink" aria-label="คัดลอกลิงก์ของงานนี้" title="คัดลอกลิงก์ของงานนี้">
                <svg class="icon" aria-hidden="true"><use href="#i-link"/></svg>
            </button>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="editTaskForm" data-act="submitEditTask" data-args='["$event"]' data-on="submit" novalidate>
            <input type="hidden" id="editTaskId">
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label" for="editTaskTitle">ชื่องาน</label>
                    <input type="text" id="editTaskTitle" class="form-control" maxlength="255" autocomplete="off">
                </div>
                <div class="form-group">
                    <label class="form-label" for="editTaskDescription">คำอธิบาย</label>
                    <textarea id="editTaskDescription" class="form-control" rows="4" maxlength="10000" placeholder="รายละเอียด ลิงก์ หรือเงื่อนไขที่ทีมต้องรู้"></textarea>
                </div>

                <!-- ช่องเฉพาะโปรเจคซอฟต์แวร์: ชนิดงาน แต้ม งานใหญ่ และงานแม่ -->
                <div id="editTaskSoftware" hidden>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="editTaskKind">ชนิดงาน</label>
                            <select id="editTaskKind" class="form-control" data-act="handleTaskKindChange" data-args='["$value"]' data-on="change">
                                <option value="epic">งานใหญ่</option>
                                <option value="story">เรื่องผู้ใช้</option>
                                <option value="task">งาน</option>
                                <option value="bug">บั๊ก</option>
                                <option value="sub">งานย่อย</option>
                            </select>
                        </div>
                        <div class="form-group" id="editTaskPointsGroup">
                            <label class="form-label" for="editTaskPoints">แต้ม</label>
                            <select id="editTaskPoints" class="form-control"></select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group" id="editTaskEpicGroup">
                            <label class="form-label" for="editTaskEpic">งานใหญ่</label>
                            <select id="editTaskEpic" class="form-control"></select>
                        </div>
                        <div class="form-group" id="editTaskParentGroup" hidden>
                            <label class="form-label" for="editTaskParent">งานแม่</label>
                            <select id="editTaskParent" class="form-control"></select>
                        </div>
                    </div>
                    <div class="form-group" id="editTaskSprintGroup" hidden>
                        <label class="form-label" for="editTaskSprint">รอบงาน</label>
                        <select id="editTaskSprint" class="form-control"></select>
                    </div>
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
                        <input type="text" id="editTaskCategory" class="form-control" maxlength="50" placeholder="เช่น ดีไซน์, โค้ด, เนื้อหา" autocomplete="off">
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
                <div class="task-custom" id="editTaskCustom" hidden></div>

                <!-- งานย่อยของเรื่องผู้ใช้/งาน/บั๊ก และงานลูกของงานใหญ่ (โปรเจคซอฟต์แวร์) -->
                <section class="dsec" id="taskSubtasksSection" hidden>
                    <h3 id="taskSubtasksHeading">งานย่อย <small>0/0</small></h3>
                    <div id="taskSubtasksList"></div>
                    <button type="button" class="btn btn-link btn-sm" id="taskAddSubtaskButton" data-act="addSubtask" hidden>+ งานย่อย</button>
                </section>
                <section class="dsec" id="taskEpicChildrenSection" hidden>
                    <h3 id="taskEpicChildrenHeading">งานในงานใหญ่นี้ <small>เสร็จ 0/0 · 0%</small></h3>
                    <progress class="task-epic-meter" id="taskEpicChildrenMeter" max="1" value="0"></progress>
                    <div id="taskEpicChildrenList"></div>
                </section>
                <section class="dsec" id="taskBlocksSection" hidden>
                    <h3 id="taskBlocksHeading">งานขวาง</h3>
                    <div id="taskBlocksList"></div>
                    <div class="dep-add" id="taskBlocksForm">
                        <select class="form-control" id="taskBlockRelation" aria-label="ความสัมพันธ์"><option value="waits">ต้องรอ</option><option value="blocks">ขวาง</option></select>
                        <input class="form-control" id="taskBlockInput" list="taskBlockOptions" placeholder="เลขหรือชื่องาน" aria-label="งานที่จะผูก" autocomplete="off">
                        <datalist id="taskBlockOptions"></datalist>
                        <button type="button" class="btn btn-sm" data-act="addTaskBlock">ผูก</button>
                    </div>
                    <p class="dep-err" id="taskBlockError" role="alert" hidden></p>
                </section>

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
                <!-- ไฟล์แนบ: ลิงก์ไปไฟล์ในคลังของคนแนบ (D-4) แขกจากลิงก์แชร์ไม่เห็น (D-5) -->
                <section class="task-files" id="taskFiles" aria-labelledby="taskFilesHeading">
                    <h3 id="taskFilesHeading">ไฟล์แนบ <small id="taskFileCount"></small></h3>
                    <div id="taskFileList"></div>
                    <div class="task-file-actions" id="taskFileActions">
                        <label class="btn btn-sm">แนบจากเครื่อง
                            <input type="file" id="taskFileInput" class="sr-only" multiple data-act="uploadTaskFiles" data-on="change">
                        </label>
                        <button type="button" class="btn btn-sm" data-act="openTaskFilePicker">เลือกจากคลังไฟล์</button>
                    </div>
                    <div class="task-file-picker" id="taskFilePicker" hidden>
                        <div class="task-file-picker-head"><span id="taskFilePickerPath">คลังไฟล์</span>
                            <button type="button" class="btn btn-link btn-sm" data-act="closeTaskFilePicker">ปิด</button></div>
                        <div id="taskFilePickerList"></div>
                    </div>
                    <p class="proj-note">ไฟล์เก็บในคลังไฟล์ของคนแนบ (รวม Google Drive ถ้าเปิดไว้) ทุกคนในโปรเจคเปิดได้</p>
                </section>

                <!-- ความเห็น: แขกจากลิงก์แชร์ไม่เห็น (D-5) ผู้ดูอย่างเดียวอ่านได้แต่เขียนไม่ได้ -->
                <section class="task-comments" id="taskComments" aria-labelledby="taskCommentsHeading">
                    <h3 id="taskCommentsHeading">ความเห็น <small id="taskCommentCount"></small></h3>
                    <div class="task-comment-list" id="taskCommentList" aria-live="polite"></div>
                    <div class="comment-composer" id="taskCommentComposer">
                        <div class="mention-pop" id="mentionPop" role="listbox" aria-label="เลือกคนที่จะกล่าวถึง" hidden></div>
                        <label class="sr-only" for="taskCommentInput">เขียนความเห็น</label>
                        <textarea id="taskCommentInput" class="form-control" rows="3" maxlength="5000" placeholder="เขียนความเห็น พิมพ์ @ เพื่อเรียกชื่อคนในทีม"
                                  data-act="onCommentInput" data-on="input" aria-autocomplete="list" aria-controls="mentionPop"></textarea>
                        <div class="comment-composer-actions">
                            <span class="proj-note">ความเห็นใหม่ขึ้นเองภายใน 1 นาที · Ctrl+Enter ส่ง</span>
                            <button type="button" class="btn btn-primary btn-sm" data-act="sendTaskComment">ส่งความเห็น</button>
                        </div>
                    </div>
                </section>
                <section class="task-history" aria-labelledby="taskHistoryHeading">
                    <h3 id="taskHistoryHeading">ประวัติ</h3>
                    <ul class="task-history-list" id="taskHistoryList"></ul>
                </section>
                <p class="form-error" id="taskError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal>ปิด</button>
                <button type="submit" class="btn btn-primary">บันทึกงาน</button>
            </div>
        </form>
    </div>
</div>

<!-- ปิดรอบงาน: สรุปผล เลือกที่ย้ายงานค้าง แล้วกลับไปคลังงาน -->
<div class="modal-backdrop" id="closeSprintModal" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="closeSprintTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="closeSprintTitle">ปิดรอบงาน</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="closeSprintForm" data-act="submitCloseSprint" data-args='["$event"]' data-on="submit" novalidate>
            <div class="modal-body">
                <div class="close-sum" id="closeSprintSummary"></div>
                <div>
                    <p class="close-left-heading"><b id="closeSprintLeftHeading">งานที่ยังไม่เสร็จ</b></p>
                    <ul class="left-list" id="closeSprintLeftList"></ul>
                </div>
                <fieldset class="close-choices">
                    <legend><b>ย้ายงานที่ยังไม่เสร็จไป</b></legend>
                    <div id="closeSprintChoices"></div>
                </fieldset>
                <p class="proj-note">งานที่เสร็จแล้วยังผูกกับรอบนี้ไว้ ใช้คิดความเร็วทีม · คอลัมน์ของงานที่ย้ายไม่เปลี่ยน</p>
                <p class="form-error" id="closeSprintError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal>ยกเลิก</button>
                <button type="submit" class="btn btn-primary">ปิดรอบ</button>
            </div>
        </form>
    </div>
</div>

<!-- Deleting a column that still holds tasks -->
<div class="modal-backdrop" id="deleteColumnModal" aria-hidden="true">
    <div class="modal modal-narrow" role="dialog" aria-labelledby="deleteColumnTitle">
        <div class="modal-header">
            <h2 class="modal-title" id="deleteColumnTitle">ลบคอลัมน์</h2>
            <button class="modal-close" type="button" aria-label="ปิด" data-close-modal>&times;</button>
        </div>
        <form id="deleteColumnForm" data-act="confirmDeleteColumn" data-args='["$event"]' data-on="submit" novalidate>
            <div class="modal-body">
                <p id="deleteColumnMessage"></p>
                <div class="form-group">
                    <label class="form-label" for="deleteColumnMoveTo">ย้ายงานไปที่</label>
                    <select id="deleteColumnMoveTo" class="form-control"></select>
                </div>
                <p class="form-hint">งานย้ายไปต่อท้ายคอลัมน์นั้น</p>
                <p class="form-error" id="deleteColumnError" role="alert" hidden></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn" data-close-modal>ยกเลิก</button>
                <button type="submit" class="btn btn-danger">ย้ายงานแล้วลบคอลัมน์</button>
            </div>
        </form>
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
