/* =====================================================
   projects-releases.js — แท็บ "เวอร์ชัน" ของโปรเจคซอฟต์แวร์ (REQ-S6)

   รายการเวอร์ชันทางซ้าย รายละเอียดทางขวา: เวอร์ชันที่ยังไม่ออกแสดงงานที่ผูกไว้
   เวอร์ชันที่ออกแล้วแสดง changelog ที่เก็บไว้ตอนออก พร้อมปุ่มคัดลอกเป็นข้อความ
   ข้อมูลมาจาก activeProjectData.releases (ส่งมากับรายการงาน) ไม่โหลดแยก
===================================================== */

const RELEASE_STATE_LABEL = { planned: 'วางแผน', active: 'กำลังทำ', released: 'ออกแล้ว' };
const RELEASE_STATE_CLASS = { planned: '', active: ' open', released: ' done' };
/** หัวกลุ่มใน changelog → ชนิดงานที่ใช้ไอคอน (ต้องตรงกับ ProjectRelease::CHANGELOG_GROUPS) */
const CHANGELOG_KIND = { 'ของใหม่': 'story', 'ปรับปรุง': 'task', 'แก้บั๊ก': 'bug' };

let selectedReleaseId = null;
let releaseModalId = null;

/** เวอร์ชันทั้งหมดของโปรเจคที่เปิดอยู่ (เรียงจากเซิร์ฟเวอร์แล้ว) */
function projectReleases() {
    return (activeProjectData && activeProjectData.releases) || [];
}

/** หาเวอร์ชันตามรหัส หรือ null */
function releaseById(id) {
    return projectReleases().find(release => Number(release.id) === Number(id)) || null;
}

/** งานทั้งหมดที่ผูกกับเวอร์ชันหนึ่ง เรียงตามเลขงาน */
function releaseTasks(id) {
    return ((activeProjectData && activeProjectData.tasks) || [])
        .filter(task => Number(task.release_id) === Number(id))
        .sort((a, b) => Number(a.number) - Number(b.number));
}

/** หัวบรรทัดของข้อความที่คัดลอก: "v1.4 (10 พ.ย. 2569)" */
function releaseTitleLine(release) {
    const date = release.released_at || release.release_date;
    return release.name + (date ? ' (' + formatDate(String(date).slice(0, 10)) + ')' : '');
}

/**
 * บรรทัดย่อยใต้ชื่อเวอร์ชัน: วันออกและจำนวนงาน
 * ที่วางแผนยังไม่มีงานเสร็จจึงบอกจำนวนงานเฉย ๆ ส่วนที่กำลังทำบอกความคืบหน้า
 * (สถานะมีป้ายของมันเองแล้ว แต่แถววางแผนใส่คำว่าวางแผนไว้ให้อ่านต่อกันได้เหมือนแบบ)
 */
function releaseSubLine(release) {
    const total = Number(release.task_count) || 0;
    if (release.state === 'released') {
        return 'ออกแล้ว ' + formatDate(String(release.released_at || '').slice(0, 10)) + ' · ' + total + ' งาน';
    }
    const when = release.release_date ? 'ออก ' + formatDate(release.release_date) : 'ยังไม่กำหนดวัน';
    if (release.state === 'planned') {
        return 'วางแผน · ' + when + ' · ' + total + ' งาน';
    }
    return when + ' · เสร็จ ' + (Number(release.done_count) || 0) + ' จาก ' + total + ' งาน';
}

/**
 * วาดแท็บเวอร์ชันใหม่ทั้งหมด (เรียกทุกครั้งที่ข้อมูลโปรเจคเปลี่ยน)
 * โปรเจคที่ไม่ใช่ซอฟต์แวร์ซ่อนแท็บ และถ้าเปิดแท็บนี้ค้างอยู่จะกลับไปบอร์ด
 */
function renderReleases() {
    const software = isSoftwareProject();
    show('tabReleases', software);
    if (!software) {
        if (projectSettingsTab === 'releases') showProjectTab('board');
        return;
    }
    show('btnAddRelease', isProjectAdmin());

    const releases = projectReleases();
    if (!releaseById(selectedReleaseId)) {
        selectedReleaseId = releases.length ? Number(releases[0].id) : null;
    }

    const list = document.getElementById('releaseList');
    if (list) {
        list.innerHTML = releases.length
            ? releases.map(release => '<button type="button" class="rel-row" data-act="selectRelease" data-args="[' + Number(release.id) + ']"'
                + ' aria-current="' + (Number(release.id) === selectedReleaseId) + '">'
                + '<span><span class="rel-name">' + escHtml(release.name) + '</span>'
                + '<span class="sub">' + escHtml(releaseSubLine(release)) + '</span></span>'
                + '<span class="state' + RELEASE_STATE_CLASS[release.state] + '">' + escHtml(RELEASE_STATE_LABEL[release.state]) + '</span>'
                + '</button>').join('')
            : '<p class="proj-note">ยังไม่มีเวอร์ชัน'
                + (isProjectAdmin() ? ' กด "+ เวอร์ชัน" เพื่อเริ่ม แล้วผูกงานจากช่องเวอร์ชันในรายละเอียดงาน' : '') + '</p>';
    }
    renderReleaseDetail();
}

/** เลือกเวอร์ชันในรายการ แล้ววาดรายละเอียดของมัน */
function selectRelease(id) {
    selectedReleaseId = Number(id);
    renderReleases();
}

/** วาดการ์ดรายละเอียดของเวอร์ชันที่เลือก (งานที่ผูก หรือ changelog เมื่อออกแล้ว) */
function renderReleaseDetail() {
    const card = document.getElementById('releaseDetail');
    if (!card) return;
    const release = releaseById(selectedReleaseId);
    card.hidden = !release;
    if (!release) { card.innerHTML = ''; return; }
    card.innerHTML = release.state === 'released' ? releasedDetailHtml(release) : openReleaseDetailHtml(release);
}

/** รายละเอียดของเวอร์ชันที่ยังไม่ออก: ความคืบหน้า งานที่ผูก และปุ่มของผู้ดูแล */
function openReleaseDetailHtml(release) {
    const tasks = releaseTasks(release.id);
    const done = tasks.filter(isDoneTask).length;
    const id = Number(release.id);
    const admin = isProjectAdmin();
    return '<div class="rel-detail-head"><h3>' + escHtml(release.name) + '</h3>'
        + (admin ? '<span class="rel-actions">'
            + (release.state === 'planned'
                ? '<button class="btn btn-sm" type="button" data-act="setReleaseState" data-args=\'[' + id + ',"active"]\'>เริ่มทำ</button>'
                : '<button class="btn btn-sm" type="button" data-act="setReleaseState" data-args=\'[' + id + ',"planned"]\'>กลับเป็นวางแผน</button>')
            + '<button class="btn btn-sm" type="button" data-act="editRelease" data-args="[' + id + ']">แก้ไข</button>'
            + '<button class="btn btn-sm btn-danger" type="button" data-act="deleteRelease" data-args="[' + id + ']">ลบ</button>'
            + '<button class="btn btn-sm btn-primary" type="button" data-act="openReleaseModal" data-args="[' + id + ']">ออกเวอร์ชัน</button>'
            + '</span>' : '')
        + '</div>'
        + '<p class="sub">' + escHtml(RELEASE_STATE_LABEL[release.state] || '') + ' · เสร็จ ' + done + ' จาก ' + tasks.length + ' งาน'
        + (release.release_date ? ' · ออก ' + escHtml(formatDate(release.release_date)) : '') + '</p>'
        + '<progress class="task-epic-meter" max="' + Math.max(tasks.length, 1) + '" value="' + done + '"'
        + ' aria-label="เสร็จ ' + done + ' จาก ' + tasks.length + ' งาน"></progress>'
        + (tasks.length
            ? tasks.map(softwareChildRow).join('')
            : '<p class="proj-note">ยังไม่มีงานในเวอร์ชันนี้ ผูกงานจากช่อง "เวอร์ชัน" ในรายละเอียดงาน</p>');
}

/** รายละเอียดของเวอร์ชันที่ออกแล้ว: changelog จัดกลุ่ม ปุ่มคัดลอก และช่องแก้ข้อความ */
function releasedDetailHtml(release) {
    const admin = isProjectAdmin();
    const text = String(release.changelog || '');
    const when = formatDate(String(release.released_at || '').slice(0, 10));
    return '<div class="rel-detail-head"><h3>สิ่งที่เปลี่ยนใน ' + escHtml(release.name) + (when ? ' · ' + escHtml(when) : '') + '</h3>'
        + '<button class="btn btn-sm btn-primary" type="button" data-act="copyChangelog">คัดลอกเป็นข้อความ</button></div>'
        + '<p class="sub">สร้างจากงานที่เสร็จและผูกกับเวอร์ชันนี้ตอนกด "ออกเวอร์ชัน"' + (admin ? ' แก้ข้อความได้ก่อนคัดลอก' : '') + '</p>'
        + '<div class="changelog">' + changelogHtml(text) + '</div>'
        + '<details class="mt-3" id="changelogDetails"><summary class="text-sm">' + (admin ? 'ดูและแก้ข้อความ' : 'ดูข้อความที่จะคัดลอก') + '</summary>'
        + '<textarea class="form-control copy-box mt-2" id="changelogText" aria-label="ข้อความ changelog"' + (admin ? '' : ' readonly') + '>'
        + escHtml(text) + '</textarea>'
        + (admin ? '<button class="btn btn-sm mt-2" type="button" data-act="saveChangelog" data-args="[' + Number(release.id) + ']">บันทึกข้อความ</button>' : '')
        + '</details>';
}

/**
 * แปลงข้อความ changelog เป็น HTML: บรรทัดที่ขึ้นต้น "- " เป็นรายการ บรรทัดอื่นเป็นหัวกลุ่ม
 * ทุกข้อความผ่าน escHtml เพราะชื่องานเป็นข้อความที่ผู้ใช้พิมพ์
 */
function changelogHtml(text) {
    const lines = text.split('\n').map(line => line.trim()).filter(Boolean);
    if (!lines.length) return '<p class="proj-note">ไม่มีงานที่เสร็จในเวอร์ชันนี้</p>';
    let html = '';
    let open = false;
    lines.forEach(line => {
        if (line.startsWith('- ')) {
            if (!open) { html += '<ul>'; open = true; }
            html += '<li>' + escHtml(line.slice(2)) + '</li>';
            return;
        }
        if (open) { html += '</ul>'; open = false; }
        const kind = CHANGELOG_KIND[line];
        html += '<h4>' + (kind ? kindIcon(kind) : '') + escHtml(line) + '</h4>';
    });
    return html + (open ? '</ul>' : '');
}

/** สร้างเวอร์ชันใหม่: ถามชื่อแล้ว POST */
async function createRelease() {
    if (!isProjectAdmin()) return;
    const name = (await askText({ title: 'สร้างเวอร์ชัน', label: 'ชื่อเวอร์ชัน', placeholder: 'เช่น v1.5', okLabel: 'สร้าง', required: true }) || '').trim();
    if (!name) return;
    try {
        const created = await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/releases', {
            method: 'POST',
            body: JSON.stringify({ name })
        });
        selectedReleaseId = Number(created.id);
        toast('สร้างเวอร์ชันแล้ว', 'success');
        await reloadReleases();
    } catch (err) {
        toast(err.message || 'สร้างเวอร์ชันไม่สำเร็จ', 'danger');
    }
}

/** แก้ชื่อและวันออกของเวอร์ชัน: ถามทีละช่อง ยกเลิกช่องไหนก็ไม่บันทึก */
async function editRelease(id) {
    const release = releaseById(id);
    if (!release || !isProjectAdmin()) return;
    const name = await askText({ title: 'แก้เวอร์ชัน', label: 'ชื่อเวอร์ชัน', value: release.name, required: true });
    if (name === null) return;
    const date = await askText({ title: 'แก้เวอร์ชัน', label: 'วันที่จะออก (เว้นว่างได้)', type: 'date', value: release.release_date || '' });
    if (date === null) return;
    await sendReleaseUpdate(id, { name: name.trim(), release_date: date }, 'แก้เวอร์ชันแล้ว');
}

/** เปลี่ยนสถานะระหว่างวางแผนกับกำลังทำ */
async function setReleaseState(id, state) {
    await sendReleaseUpdate(id, { state }, state === 'active' ? 'เริ่มทำเวอร์ชันนี้แล้ว' : 'กลับเป็นวางแผนแล้ว');
}

/** บันทึก changelog ที่แก้ในช่องข้อความ */
async function saveChangelog(id) {
    const box = document.getElementById('changelogText');
    if (!box) return;
    await sendReleaseUpdate(id, { changelog: box.value }, 'บันทึกข้อความแล้ว');
}

/** PUT ค่าที่แก้ของเวอร์ชัน แล้วโหลดข้อมูลใหม่ ข้อผิดพลาดขึ้นเป็น toast */
async function sendReleaseUpdate(id, body, done) {
    try {
        await apiFetch(BASE_URL + '/api/projects/releases/' + Number(id), { method: 'PUT', body: JSON.stringify(body) });
        toast(done, 'success');
        await reloadReleases();
    } catch (err) {
        toast(err.message || 'บันทึกไม่สำเร็จ', 'danger');
    }
}

/** ลบเวอร์ชันหลังยืนยัน งานที่ผูกไว้ยังอยู่แต่ไม่ผูกเวอร์ชันแล้ว */
async function deleteRelease(id) {
    const release = releaseById(id);
    if (!release || !isProjectAdmin()) return;
    const count = Number(release.task_count) || 0;
    const ok = await confirmAction(
        'ลบเวอร์ชัน "' + release.name + '"?' + (count ? ' งาน ' + count + ' งานจะไม่ผูกเวอร์ชันแต่ยังอยู่ครบ' : ''),
        'ลบเวอร์ชัน', 'ลบเวอร์ชัน'
    );
    if (!ok) return;
    try {
        await apiFetch(BASE_URL + '/api/projects/releases/' + Number(id), { method: 'DELETE' });
        selectedReleaseId = null;
        toast('ลบเวอร์ชันแล้ว', 'success');
        await reloadReleases();
    } catch (err) {
        toast(err.message || 'ลบไม่สำเร็จ', 'danger');
    }
}

/**
 * เริ่มออกเวอร์ชัน: ไม่มีงานค้างถามยืนยันอย่างเดียว มีงานค้างเปิดกล่องเลือกที่ย้าย
 * (ตัวเลือก: เวอร์ชันอื่นที่ยังไม่ออก หรือไม่ผูกเวอร์ชัน)
 */
async function openReleaseModal(id) {
    const release = releaseById(id);
    if (!release || !isProjectAdmin()) return;
    const tasks = releaseTasks(release.id);
    const unfinished = tasks.filter(task => !isDoneTask(task));

    if (!unfinished.length) {
        const ok = await confirmAction('ออกเวอร์ชัน ' + release.name + '? changelog จะสร้างจากงานที่เสร็จ', 'ออกเวอร์ชัน', 'ออกเวอร์ชัน', 'primary');
        if (ok) await sendRelease(release.id, null);
        return;
    }

    document.getElementById('releaseModalTitle').textContent = 'ออกเวอร์ชัน ' + release.name;
    document.getElementById('releaseSummary').innerHTML =
        '<div><b>' + (tasks.length - unfinished.length) + '</b>งานเสร็จ</div>'
        + '<div><b>' + unfinished.length + '</b>งานยังไม่เสร็จ</div>';
    document.getElementById('releaseLeftList').innerHTML = unfinished.map(task => '<li>' + kindIcon(task.kind || 'task')
        + (taskKey(task) ? '<span class="key">' + escHtml(taskKey(task)) + '</span>' : '')
        + '<span class="left-title">' + escHtml(task.title || '') + '</span></li>').join('');
    const targets = projectReleases().filter(item => item.state !== 'released' && Number(item.id) !== Number(release.id));
    document.getElementById('releaseChoices').innerHTML = targets.map((target, index) =>
        '<label class="choice"><input type="radio" name="releaseCarry" value="' + Number(target.id) + '"' + (index === 0 ? ' checked' : '') + '>'
        + '<span>' + escHtml(target.name) + '<small>' + escHtml(releaseSubLine(target)) + '</small></span></label>').join('')
        + '<label class="choice"><input type="radio" name="releaseCarry" value=""' + (targets.length ? '' : ' checked') + '>'
        + '<span>ไม่ผูกเวอร์ชัน<small>เลือกเวอร์ชันให้งานเหล่านี้ทีหลัง</small></span></label>';
    formErrorLine('releaseError', '');
    releaseModalId = Number(release.id);
    openModal('releaseModal');
}

/** ส่งฟอร์มในกล่องออกเวอร์ชันตามปลายทางที่เลือก */
async function submitRelease(event) {
    event.preventDefault();
    if (!releaseModalId) return;
    const selected = document.querySelector('#releaseModal input[name="releaseCarry"]:checked');
    if (!selected) { formErrorLine('releaseError', 'เลือกที่ย้ายงานค้างก่อน'); return; }
    await sendRelease(releaseModalId, selected.value ? Number(selected.value) : null);
}

/** POST ออกเวอร์ชัน แล้วโหลดใหม่และเลือกเวอร์ชันนั้นเพื่อให้เห็น changelog ทันที */
async function sendRelease(id, carry) {
    try {
        const result = await apiFetch(BASE_URL + '/api/projects/releases/' + Number(id) + '/release', {
            method: 'POST',
            body: JSON.stringify({ carry })
        });
        releaseModalId = null;
        closeModal('releaseModal');
        const moved = Number(result.carried) || 0;
        toast('ออกเวอร์ชันแล้ว' + (moved ? ' ย้ายงานค้าง ' + moved + ' งาน' : ''), 'success');
        selectedReleaseId = Number(id);
        await reloadReleases();
    } catch (err) {
        if (document.getElementById('releaseModal').classList.contains('active')) {
            formErrorLine('releaseError', err.message || 'ออกเวอร์ชันไม่สำเร็จ');
        } else {
            toast(err.message || 'ออกเวอร์ชันไม่สำเร็จ', 'danger');
        }
    }
}

/**
 * คัดลอก changelog เป็นข้อความธรรมดา (บรรทัดหัวคือชื่อและวันที่ออก)
 * คลิปบอร์ดใช้ไม่ได้ (เช่นหน้า http) เปิดช่องข้อความแล้วเลือกไว้ให้กดคัดลอกเอง
 */
async function copyChangelog() {
    const release = releaseById(selectedReleaseId);
    const box = document.getElementById('changelogText');
    if (!release || !box) return;
    const text = releaseTitleLine(release) + '\n\n' + box.value;
    try {
        await navigator.clipboard.writeText(text);
        toast('คัดลอกแล้ว', 'success');
    } catch (_) {
        document.getElementById('changelogDetails').open = true;
        box.focus();
        box.select();
        toast('กดคัดลอกจากช่องข้อความได้เลย', 'info');
    }
}

/** โหลดโปรเจคใหม่แล้วกลับมาที่แท็บเวอร์ชัน (ข้อมูลเวอร์ชันมากับรายการงาน) */
async function reloadReleases() {
    await selectProject(activeProjectId);
    showProjectTab('releases');
}
