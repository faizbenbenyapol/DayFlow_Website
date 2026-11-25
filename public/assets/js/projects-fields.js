/* ===== ช่องข้อมูลเพิ่มเติมของโปรเจค (DayFlow) =====
   วาดหน้าตั้งค่าช่อง (เพิ่ม / เปลี่ยนชื่อ / แก้ตัวเลือก / เรียง / ลบ)
   วาดช่องกรอกค่าในแผงรายละเอียดงาน และตัวช่วยอ่าน/แสดงค่าให้มุมมองรายการ
   classic script แชร์ global กับไฟล์อื่น ===== */

const FIELD_KIND_LABEL = { text: 'ข้อความ', number: 'ตัวเลข', date: 'วันที่', select: 'ตัวเลือก', person: 'คน', check: 'ติ๊ก (ใช่/ไม่ใช่)' };
let fieldSortable = null;

/** คืนรายการช่องข้อมูลของโปรเจคปัจจุบัน ([] ถ้าไม่มี) */
function projectFields() {
    return (activeProjectData && activeProjectData.fields) || [];
}

/** แปลง task.custom (สตริง JSON) เป็น object ค่า; null/ผิดรูป/ไม่ใช่ object คืน {} */
function taskCustom(task) {
    try {
        const data = JSON.parse((task && task.custom) || 'null');
        return data && typeof data === 'object' && !Array.isArray(data) ? data : {};
    } catch (err) {
        return {};
    }
}

/** ชื่อที่แสดงของสมาชิกตาม user id; ไม่พบคืน '' */
function memberName(userId) {
    if (userId === null || userId === undefined || userId === '') return '';
    const member = (projectMembers || []).find((m) => String(m.id) === String(userId));
    return member ? (member.display_name || member.username || '') : '';
}

/** ข้อความแสดงค่าของช่องสำหรับงานหนึ่ง; ไม่มีค่าคืน '' */
function fieldValueText(field, value) {
    if (value === undefined || value === null || value === '') return '';
    if (field.kind === 'number') return Number(value).toLocaleString('th-TH');
    if (field.kind === 'date') return formatDate(value);
    if (field.kind === 'person') return memberName(value) || 'อดีตสมาชิก';
    if (field.kind === 'check') return 'ใช่';
    return String(value);
}

/** ค่าที่ใช้เรียงลำดับของช่อง; ไม่มีค่าคืน null */
function fieldSortValue(field, value) {
    if (value === undefined || value === null || value === '') return null;
    if (field.kind === 'number') return Number(value);
    if (field.kind === 'check') return 1;
    if (field.kind === 'person') return memberName(value);
    return String(value);
}

/** วาดหน้าตั้งค่าช่องลงใน #fieldsEditor และเปิด/ปิดฟอร์มเพิ่มช่องตามสิทธิ์และจำนวนช่อง */
function renderFieldsSettings() {
    if (!isProjectAdmin()) {
        show('fieldsEditor', false);
        show('fieldAddForm', false);
        show('fieldsDenied', true);
        return;
    }
    show('fieldsEditor', true);
    show('fieldAddForm', true);
    show('fieldsDenied', false);

    try {
        if (fieldSortable) fieldSortable.destroy();
    } catch (err) {
        fieldSortable = null;
    }
    fieldSortable = null;

    const fields = projectFields();
    let html = '<p class="proj-note">ช่องที่เพิ่มอยู่ในรายละเอียดงานทุกงาน และเป็นคอลัมน์ในมุมมองรายการ เรียงและกรองได้ สูงสุด 10 ช่อง ลาก ⠿ เพื่อเรียง</p>';
    if (!fields.length) {
        html += '<p class="proj-note" id="fieldsEmpty">ยังไม่มีช่องข้อมูล เพิ่มช่องแรกด้านล่าง</p>';
    } else {
        html += '<div id="fieldLines">' + fields.map((field) => {
            const name = escHtml(field.name);
            const type = escHtml(FIELD_KIND_LABEL[field.kind] || field.kind)
                + (field.kind === 'select' ? ': ' + escHtml((field.options || []).join(', ')) : '');
            const optionsBtn = field.kind === 'select'
                ? '<button class="btn btn-sm btn-ghost" type="button" data-act="editFieldOptions" data-args="[' + field.id + ']">แก้ตัวเลือก</button> '
                : '';
            return '<div class="col-line field-line" data-field-id="' + field.id + '">'
                + '<span class="drag-handle" aria-hidden="true">⠿</span>'
                + '<input class="form-control" value="' + name + '" aria-label="ชื่อช่อง ' + name + '" maxlength="60" autocomplete="off"'
                + ' data-original="' + name + '" data-act="renameField" data-args=\'[' + field.id + ', "$value", "$el"]\' data-on="change">'
                + '<span class="field-type">' + type + '</span>'
                + '<span class="field-actions">' + optionsBtn
                + '<button class="btn btn-sm btn-ghost" type="button" data-act="deleteField" data-args="[' + field.id + ']">ลบ</button>'
                + '</span></div>';
        }).join('') + '</div>';
    }
    document.getElementById('fieldsEditor').innerHTML = html;

    const isFull = fields.length >= 10;
    const submitBtn = document.getElementById('fieldAddSubmit');
    if (submitBtn) submitBtn.disabled = isFull;
    show('fieldsFull', isFull);

    const lines = document.getElementById('fieldLines');
    if (lines && typeof Sortable !== 'undefined') {
        fieldSortable = Sortable.create(lines, {
            handle: '.drag-handle',
            animation: 160,
            ghostClass: 'sortable-ghost',
            onEnd: () => {
                saveFieldOrder();
            },
        });
    }
}

/** แสดงกลุ่มตัวเลือกเมื่อชนิดช่องเป็น select */
function toggleFieldOptions(kind) {
    show('fieldOptionsGroup', kind === 'select');
}

/** เพิ่มช่องใหม่จากฟอร์มด้านล่าง (submit) — validate, POST แล้วโหลดโปรเจคใหม่ */
async function submitAddField(event) {
    if (event) event.preventDefault();
    const nameInput = document.getElementById('fieldName');
    const kindInput = document.getElementById('fieldKind');
    const optionsInput = document.getElementById('fieldOptions');
    const name = nameInput.value.trim();
    const kind = kindInput.value;
    const options = kind === 'select'
        ? optionsInput.value.split('\n').map((line) => line.trim()).filter(Boolean)
        : [];
    if (!name) {
        formErrorLine('fieldError', 'ตั้งชื่อช่องก่อน');
        nameInput.focus();
        return;
    }
    if (kind === 'select' && !options.length) {
        formErrorLine('fieldError', 'ใส่ตัวเลือกอย่างน้อย 1 บรรทัด');
        optionsInput.focus();
        return;
    }
    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/fields', {
            method: 'POST',
            body: JSON.stringify({ name, kind, options }),
        });
        document.getElementById('fieldAddForm').reset();
        toggleFieldOptions(document.getElementById('fieldKind').value);
        formErrorLine('fieldError', '');
        toast('เพิ่มช่อง "' + name + '" แล้ว', 'success');
        await afterColumnsChange();
    } catch (err) {
        formErrorLine('fieldError', err.message || 'เพิ่มช่องไม่สำเร็จ');
    }
}

/** เปลี่ยนชื่อช่องจาก input (change); เท่าเดิม/ว่างจะคืนค่าเดิม ไม่ยิง API */
async function renameField(fieldId, value, input) {
    const original = input.dataset.original || '';
    const name = (value || '').trim();
    if (!name || name === original) {
        input.value = original;
        return;
    }
    try {
        await apiFetch(BASE_URL + '/api/projects/fields/' + fieldId, {
            method: 'PUT',
            body: JSON.stringify({ name }),
        });
        input.dataset.original = name;
        toast('เปลี่ยนชื่อช่องแล้ว', 'success');
        await afterColumnsChange();
    } catch (err) {
        input.value = original;
        toast(err.message || 'เปลี่ยนชื่อช่องไม่สำเร็จ', 'danger');
    }
}

/** แก้ตัวเลือกของช่อง select (คั่นด้วยจุลภาค) และเตือนถ้ามีงานใช้ตัวเลือกที่ถูกเอาออก */
async function editFieldOptions(fieldId) {
    const field = projectFields().find((f) => String(f.id) === String(fieldId));
    if (!field) return;
    const answer = await askText({
        title: 'ตัวเลือกของช่อง "' + field.name + '"',
        label: 'ตัวเลือก คั่นด้วยจุลภาค (,)',
        value: (field.options || []).join(', '),
        okLabel: 'บันทึก',
        required: true,
    });
    if (answer === null) return;
    const options = Array.from(new Set(answer.split(',').map((part) => part.trim()).filter(Boolean)));
    if (!options.length) {
        toast('ต้องมีตัวเลือกอย่างน้อย 1 ตัว', 'danger');
        return;
    }
    const affected = ((activeProjectData && activeProjectData.tasks) || []).filter((task) => {
        const value = taskCustom(task)[field.id];
        return value !== undefined && value !== null && value !== '' && options.indexOf(String(value)) === -1;
    }).length;
    if (affected > 0) {
        const ok = await confirmAction(
            'งาน ' + affected + ' งานใช้ตัวเลือกที่ถูกเอาออก ค่าในงานเหล่านั้นจะถูกล้าง',
            'บันทึกตัวเลือก',
            'แก้ตัวเลือก',
            'danger'
        );
        if (!ok) return;
    }
    try {
        await apiFetch(BASE_URL + '/api/projects/fields/' + fieldId, {
            method: 'PUT',
            body: JSON.stringify({ options }),
        });
        toast('บันทึกตัวเลือกแล้ว', 'success');
        await afterColumnsChange();
    } catch (err) {
        toast(err.message || 'บันทึกตัวเลือกไม่สำเร็จ', 'danger');
    }
}

/** ลบช่องข้อมูล (ยืนยันก่อน) พร้อมบอกจำนวนงานที่จะเสียค่าไป */
async function deleteField(fieldId) {
    const field = projectFields().find((f) => String(f.id) === String(fieldId));
    if (!field) return;
    const used = ((activeProjectData && activeProjectData.tasks) || []).filter((task) => {
        const value = taskCustom(task)[field.id];
        return value !== undefined && value !== null && value !== '';
    }).length;
    const ok = await confirmAction(
        'ลบช่อง "' + field.name + '"?' + (used ? ' ค่าที่กรอกไว้ใน ' + used + ' งานจะหายไปด้วย' : '') + ' ย้อนกลับไม่ได้',
        'ลบช่อง',
        'ลบช่องข้อมูล',
        'danger'
    );
    if (!ok) return;
    try {
        await apiFetch(BASE_URL + '/api/projects/fields/' + fieldId, { method: 'DELETE' });
        toast('ลบช่อง "' + field.name + '" แล้ว', 'success');
        await afterColumnsChange();
    } catch (err) {
        toast(err.message || 'ลบช่องไม่สำเร็จ', 'danger');
    }
}

/** บันทึกลำดับช่องหลังลากเรียง แล้วโหลดโปรเจคใหม่ (ล้มเหลวก็คืนลำดับเดิมจากเซิร์ฟเวอร์) */
async function saveFieldOrder() {
    const lines = document.getElementById('fieldLines');
    if (!lines) return;
    const ids = Array.from(lines.querySelectorAll('[data-field-id]')).map((el) => Number(el.dataset.fieldId));
    try {
        await apiFetch(BASE_URL + '/api/projects/' + activeProjectId + '/fields/reorder', {
            method: 'POST',
            body: JSON.stringify({ ids }),
        });
        await afterColumnsChange();
    } catch (err) {
        toast(err.message || 'บันทึกลำดับช่องไม่สำเร็จ', 'danger');
        await afterColumnsChange();
    }
}

/** วาดช่องกรอกข้อมูลเพิ่มเติมของงานลงใน #editTaskCustom (ซ่อนถ้าโปรเจคไม่มีช่อง) */
function renderTaskCustomFields(task) {
    const box = document.getElementById('editTaskCustom');
    if (!box) return;
    const fields = projectFields();
    if (!fields.length) {
        box.innerHTML = '';
        box.hidden = true;
        return;
    }
    box.hidden = false;
    const values = taskCustom(task);
    const members = projectMembers || [];
    const disabled = isViewer() ? ' disabled' : '';
    box.innerHTML = fields.map((field) => {
        const fid = 'taskField-' + field.id;
        const name = escHtml(field.name);
        const attrs = 'class="form-control" id="' + fid + '" data-field-id="' + field.id + '"' + disabled;
        const raw = values[field.id];
        const value = raw === undefined || raw === null ? '' : raw;
        if (field.kind === 'check') {
            return '<div class="form-group"><label class="check-item"><input type="checkbox" id="' + fid
                + '" data-field-id="' + field.id + '"' + (value ? ' checked' : '') + disabled + '> ' + name + '</label></div>';
        }
        let input;
        if (field.kind === 'text' || field.kind === 'number' || field.kind === 'date') {
            const extra = field.kind === 'text'
                ? ' maxlength="500" autocomplete="off"'
                : (field.kind === 'number' ? ' step="any" inputmode="decimal"' : '');
            input = '<input type="' + field.kind + '"' + extra + ' ' + attrs + ' value="' + escHtml(String(value)) + '">';
        } else if (field.kind === 'select') {
            input = '<select ' + attrs + '><option value="">—</option>'
                + (field.options || []).map((opt) => '<option value="' + escHtml(opt) + '"'
                    + (String(opt) === String(value) ? ' selected' : '') + '>' + escHtml(opt) + '</option>').join('')
                + '</select>';
        } else if (field.kind === 'person') {
            const known = members.some((m) => String(m.id) === String(value));
            input = '<select ' + attrs + '><option value="">ยังไม่ระบุ</option>'
                + members.map((m) => '<option value="' + escHtml(m.id) + '"'
                    + (String(m.id) === String(value) ? ' selected' : '') + '>'
                    + escHtml(m.display_name || m.username) + '</option>').join('')
                + (value !== '' && !known ? '<option value="' + escHtml(String(value)) + '" selected>อดีตสมาชิก</option>' : '')
                + '</select>';
        } else {
            input = '<input type="text" maxlength="500" autocomplete="off" ' + attrs + ' value="' + escHtml(String(value)) + '">';
        }
        return '<div class="form-group"><label class="form-label" for="' + fid + '">' + name + '</label>' + input + '</div>';
    }).join('');
}

/** เก็บค่าทุกช่องใน #editTaskCustom เป็น object {fieldId: ค่า}; ช่องว่างเป็น null; ไม่มีช่องคืน {} */
function collectTaskCustom() {
    const box = document.getElementById('editTaskCustom');
    if (!box) return {};
    const fields = projectFields();
    const result = {};
    Array.from(box.querySelectorAll('[data-field-id]')).forEach((el) => {
        const fieldId = el.dataset.fieldId;
        const field = fields.find((f) => String(f.id) === String(fieldId));
        const kind = field ? field.kind : '';
        if (kind === 'check') {
            result[fieldId] = el.checked ? true : null;
        } else if (kind === 'number') {
            result[fieldId] = el.value === '' ? null : Number(el.value);
        } else if (kind === 'person') {
            result[fieldId] = el.value === '' ? null : parseInt(el.value, 10);
        } else {
            const trimmed = el.value.trim();
            result[fieldId] = trimmed === '' ? null : trimmed;
        }
    });
    return result;
}
