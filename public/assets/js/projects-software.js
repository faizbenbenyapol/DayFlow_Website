/* =====================================================
   projects-software.js — helpers shared by the software-only screens
   Loaded on the projects page before projects-views.js; shares its state.

   Everything here is only used when the open project's type is 'software'.
   Other project types keep the board, list and panel they have always had.
===================================================== */

/** ชื่อภาษาไทยของชนิดงาน ใช้ทั้งป้ายตัวเลือกและคำอธิบายไอคอน */
const KIND_LABEL = { epic: 'งานใหญ่', story: 'เรื่องผู้ใช้', task: 'งาน', bug: 'บั๊ก', sub: 'งานย่อย' };

let softwareTaskIndex = { byId: new Map(), children: new Map() };
let softwareBlockIndex = { blockers: new Map(), blocked: new Map() };

/** true เมื่อโปรเจคที่เปิดอยู่เป็นประเภทซอฟต์แวร์ */
function isSoftwareProject() {
    return !!(activeProjectData && activeProjectData.project && activeProjectData.project.type === 'software');
}

/** เลขงานพร้อมตัวย่อของโปรเจค เช่น DF-49 หรือคืนสตริงว่างเมื่องานยังไม่มีเลข */
function taskKey(task) {
    if (!task || !task.number || !isSoftwareProject()) return '';
    const prefix = activeProjectData.project.key_prefix || '';
    return prefix ? prefix + '-' + task.number : '';
}

/** ไอคอนชนิดงาน ใช้รูปทรงที่ต่างกันจึงแยกได้แม้ไม่เห็นสี */
function kindIcon(kind) {
    if (!KIND_LABEL[kind]) return '';
    return '<span class="ticon" data-type="' + escHtml(kind) + '" title="' + escHtml(KIND_LABEL[kind]) + '">'
        + '<svg aria-hidden="true"><use href="#i-kind-' + escHtml(kind) + '"/></svg></span>';
}

/** ตัวเลือกแต้มของโปรเจคตามชุดแต้มที่ตั้งไว้ */
function pointChoices() {
    const project = activeProjectData && activeProjectData.project;
    return project && project.point_scale === 'ten'
        ? [1, 2, 3, 4, 5, 6, 7, 8, 9, 10]
        : [1, 2, 3, 5, 8, 13, 21];
}

/** ป้ายแต้มกลม แสดง – เมื่อยังไม่ได้ใส่แต้ม */
function pointsPill(points) {
    if (points == null || points === '' || Number(points) <= 0) {
        return '<span class="pts empty" title="ยังไม่ใส่แต้ม">–</span>';
    }
    return '<span class="pts" title="' + escHtml(String(points)) + ' แต้ม">' + escHtml(String(points)) + '</span>';
}

/**
 * สร้างดัชนีงานของโปรเจคที่โหลดอยู่ครั้งเดียวต่อการวาดหนึ่งครั้ง
 * ใช้แทนการค้นงานซ้ำด้วย find ในลูปงานทุกงาน
 */
function buildTaskIndex() {
    const byId = new Map();
    const children = new Map();
    ((activeProjectData && activeProjectData.tasks) || []).forEach(task => {
        byId.set(Number(task.id), task);
        if (task.parent_id == null) return;
        const parent = Number(task.parent_id);
        if (!children.has(parent)) children.set(parent, []);
        children.get(parent).push(task);
    });
    softwareTaskIndex = { byId, children };
    buildBlockIndex();
    return softwareTaskIndex;
}

/**
 * สร้างดัชนีขอบงานขวางจาก activeProjectData.blocks ในการวนรอบเดียว
 * โดยไม่ค้นงานซ้ำในลูปของการ์ดทุกใบ
 */
function buildBlockIndex() {
    const blockers = new Map();   // งานที่ต้องรอ → รายการงานที่มันรอ
    const blocked = new Map();    // งานที่ขวาง → รายการงานที่มันขวาง
    ((activeProjectData && activeProjectData.blocks) || []).forEach(edge => {
        const blockerId = Number(edge.blocker_id);
        const blockedId = Number(edge.blocked_id);
        if (!blockers.has(blockedId)) blockers.set(blockedId, []);
        blockers.get(blockedId).push(blockerId);
        if (!blocked.has(blockerId)) blocked.set(blockerId, []);
        blocked.get(blockerId).push(blockedId);
    });
    softwareBlockIndex = { blockers, blocked };
    return softwareBlockIndex;
}

/**
 * งานที่ต้องเสร็จก่อนงานนี้ (blockers)
 * @param {number} taskId รหัสงาน
 * @returns {Array<object>} งานที่ขวางมัน เรียงตามลำดับขอบที่ได้จากเซิร์ฟเวอร์
 */
function blockersOf(taskId) {
    return (softwareBlockIndex.blockers.get(Number(taskId)) || [])
        .map(id => softwareTaskIndex.byId.get(id))
        .filter(Boolean);
}

/**
 * งานที่ต้องรองานนี้ (สิ่งที่มันขวาง)
 * @param {number} taskId รหัสงาน
 * @returns {Array<object>} งานที่มันขวาง
 */
function blockedTasksOf(taskId) {
    return (softwareBlockIndex.blocked.get(Number(taskId)) || [])
        .map(id => softwareTaskIndex.byId.get(id))
        .filter(Boolean);
}

/**
 * งานที่ยังไม่เสร็จและยังขวางงานนี้อยู่ (ใช้ขึ้นป้าย "ติด")
 * @param {object} task งาน
 * @returns {Array<object>} blockers ที่ยังไม่ done
 */
function openBlockers(task) {
    return blockersOf(task.id).filter(blocker => !isDoneTask(blocker));
}

/** งานลูกโดยตรงของงานหนึ่ง จากดัชนีที่สร้างไว้ต่อการวาดหนึ่งครั้ง */
function childrenOf(taskId) {
    return softwareTaskIndex.children.get(Number(taskId)) || [];
}

/** จำนวนงานลูกที่เสร็จและทั้งหมดของงานใหญ่ คิดจากงานลูกโดยตรง */
function epicProgress(epicId) {
    const children = childrenOf(epicId);
    return {
        done: children.filter(isDoneTask).length,
        total: children.length
    };
}
