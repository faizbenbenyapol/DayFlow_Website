#!/usr/bin/env node
// =====================================================
// รีเพลย์ migration บนสำเนาฐานข้อมูล (DayFlow, P0a slice 1)
// =====================================================
//   node scripts/replay-migration.mjs [--keep] [--inject-fault] [--out <dir>]
//
// คัดลอกฐานข้อมูล dayflow ไปเป็น dayflow_replay ในคอนเทนเนอร์ dayflow-db
// แล้วรัน migration ทั้งหมดบนสำเนา จากนั้นตรวจว่า
//   1. ค่าเดิมของทุกแถว/ทุกคอลัมน์ยังอยู่หลังรัน migration (before -> after1)
//   2. รัน migration ซ้ำอีกครั้งไม่เปลี่ยนอะไรเลย (after1 -> after2)
// ห้ามคำสั่งใดในสคริปต์นี้เขียนลงฐานข้อมูล dayflow

import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';

const DB_CONTAINER = 'dayflow-db';
const APP_CONTAINER = 'dayflow-app';
const SOURCE_DB = 'dayflow';
const REPLAY_DB = 'dayflow_replay';
const MAX_MISMATCHES = 20;

// =====================================================
// ตัวช่วยพื้นฐาน
// =====================================================

/** ความผิดพลาดระดับปฏิบัติการ (docker/คำสั่ง/ข้อมูลไม่พร้อม) → exit code 2 */
class OperationalError extends Error {}

/**
 * เรียกคำสั่ง docker แล้วคืน stdout
 *
 * รับ: args = อาร์กิวเมนต์ที่ส่งให้ docker
 * คืน: string ของ stdout (ไม่ตัด whitespace)
 * ผลข้างเคียง: รันโปรเซสย่อย ถ้าคำสั่งล้มเหลวโยน OperationalError พร้อม stderr
 */
function docker(args) {
    const result = spawnSync('docker', args, { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024 });
    if (result.error) {
        throw new OperationalError(`เรียก docker ไม่ได้: ${result.error.message}`);
    }
    if (result.status !== 0) {
        const detail = (result.stderr || result.stdout || '').trim();
        throw new OperationalError(
            `คำสั่งล้มเหลว (exit ${result.status ?? result.signal}): docker ${args.join(' ')}\n${detail}`
        );
    }
    return result.stdout;
}

/**
 * อ่านตัวเลือกจาก command line
 *
 * รับ: argv = อาร์กิวเมนต์หลังชื่อสคริปต์
 * คืน: { keep: boolean, injectFault: boolean, out: string|null }
 * ผลข้างเคียง: ไม่มี (โยน OperationalError ถ้าเจอตัวเลือกที่ไม่รู้จัก)
 */
function parseArgs(argv) {
    const options = { keep: false, injectFault: false, out: null };

    for (let i = 0; i < argv.length; i++) {
        const arg = argv[i];
        if (arg === '--keep') {
            options.keep = true;
        } else if (arg === '--inject-fault') {
            options.injectFault = true;
        } else if (arg === '--out') {
            options.out = argv[++i] ?? null;
            if (options.out === null || options.out === '') {
                throw new OperationalError('--out ต้องตามด้วยชื่อโฟลเดอร์');
            }
        } else {
            throw new OperationalError(`ไม่รู้จักตัวเลือก: ${arg}`);
        }
    }

    return options;
}

/**
 * แปลง stdout ของสคริปต์ PHP เป็น snapshot object
 *
 * รับ: stdout = ข้อความ JSON, label = ชื่อ snapshot ไว้ใส่ในข้อความ error
 * คืน: { database: string, tables: object }
 * ผลข้างเคียง: โยน OperationalError ถ้า parse ไม่ได้หรือรูปร่างไม่ถูกต้อง
 */
function parseSnapshot(stdout, label) {
    let data;
    try {
        data = JSON.parse(stdout);
    } catch {
        throw new OperationalError(`snapshot ${label} ไม่ใช่ JSON:\n${stdout.trim().slice(-2000)}`);
    }
    if (typeof data?.database !== 'string' || typeof data?.tables !== 'object' || data.tables === null) {
        throw new OperationalError(`snapshot ${label} มีรูปร่างไม่ถูกต้อง`);
    }
    return data;
}

// =====================================================
// ขั้นตอนบนฐานข้อมูลรีเพลย์
// =====================================================

/**
 * คัดลอก dayflow ไปเป็น dayflow_replay ด้วย dump/restore ฝั่งคอนเทนเนอร์
 *
 * รับ: ไม่มี (ใช้ค่าคงที่ SOURCE_DB/REPLAY_DB)
 * คืน: ไม่มี
 * ผลข้างเคียง: DROP/CREATE dayflow_replay, ให้สิทธิ์ผู้ใช้แอป แล้ว restore ข้อมูล
 *               รหัสผ่าน root ถูกอ่านโดย shell ในคอนเทนเนอร์เท่านั้น
 */
function copyDatabase() {
    const script = [
        'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"',
        `mariadb -uroot -e "DROP DATABASE IF EXISTS ${REPLAY_DB}; CREATE DATABASE ${REPLAY_DB} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; GRANT ALL ON ${REPLAY_DB}.* TO \\"$MARIADB_USER\\"@\\"%\\";"`,
        `mariadb-dump -uroot --single-transaction ${SOURCE_DB} | mariadb -uroot ${REPLAY_DB}`,
    ].join(' && ');

    docker(['exec', DB_CONTAINER, 'sh', '-c', script]);
}

/**
 * ลบฐานข้อมูลรีเพลย์ทิ้ง
 *
 * รับ: ไม่มี
 * คืน: ไม่มี
 * ผลข้างเคียง: DROP DATABASE dayflow_replay (ไม่แตะ dayflow)
 */
function dropReplayDatabase() {
    const script = 'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; '
        + `mariadb -uroot -e "DROP DATABASE IF EXISTS ${REPLAY_DB};"`;
    docker(['exec', DB_CONTAINER, 'sh', '-c', script]);
}

/**
 * รันคำสั่ง SQL เดียวบนฐานข้อมูลรีเพลย์ในฐานะ root
 *
 * รับ: sql = คำสั่ง SQL ที่ไม่มีอักขระ single quote ที่ไม่ได้ escape
 * คืน: string ของ stdout (โหมด -N -B คือไม่มีหัวตารางและคั่นด้วย tab)
 * ผลข้างเคียง: ทำงานกับ dayflow_replay เท่านั้น
 */
function replaySql(sql) {
    const quoted = `'${sql.replace(/'/g, `'\\''`)}'`;
    const script = 'export MYSQL_PWD="$MARIADB_ROOT_PASSWORD"; '
        + `mariadb -uroot -N -B ${REPLAY_DB} -e ${quoted}`;
    return docker(['exec', DB_CONTAINER, 'sh', '-c', script]);
}

/**
 * รัน scripts/migrate.php บนฐานข้อมูลรีเพลย์
 *
 * รับ: extraArgs = อาร์กิวเมนต์เพิ่มเติมของ migrate.php (เช่น --force)
 * คืน: ไม่มี
 * ผลข้างเคียง: เปลี่ยน schema/ข้อมูลใน dayflow_replay
 */
function runMigrations(extraArgs = []) {
    docker([
        'exec', '-e', `DB_NAME=${REPLAY_DB}`,
        APP_CONTAINER, 'php', '/var/www/html/scripts/migrate.php', ...extraArgs,
    ]);
}

/**
 * เก็บ snapshot ของ dayflow_replay ผ่านสคริปต์ PHP (อ่านอย่างเดียว)
 *
 * รับ: label = ชื่อ snapshot สำหรับข้อความ error
 * คืน: snapshot object
 * ผลข้างเคียง: เปิด container ชั่วคราวเพื่อ SELECT เท่านั้น
 */
function takeSnapshot(label) {
    const stdout = docker([
        'exec', '-e', `DB_NAME=${REPLAY_DB}`,
        APP_CONTAINER, 'php', '/var/www/html/scripts/project-snapshot.php',
    ]);
    return parseSnapshot(stdout, label);
}

/**
 * สร้าง fault หนึ่งจุดหลัง after1 เพื่อพิสูจน์ว่าการเทียบจับการเปลี่ยนแปลงได้
 *
 * รับ: ไม่มี
 * คืน: ไม่มี
 * ผลข้างเคียง: เลื่อน position ของ project_tasks แถวแรก (ORDER BY id) ขึ้น 1
 *               ใน dayflow_replay เท่านั้น; ข้ามถ้าตารางว่าง
 */
function injectFault() {
    const count = Number(replaySql('SELECT COUNT(*) FROM project_tasks').trim());
    if (!Number.isFinite(count) || count === 0) {
        console.log('--inject-fault: project_tasks ว่าง จึงข้ามการสร้าง fault');
        return;
    }
    replaySql('UPDATE project_tasks SET position = position + 1, updated_at = updated_at ORDER BY id LIMIT 1');
    console.log('--inject-fault: เลื่อน position ของ project_tasks แถวแรกใน dayflow_replay แล้ว');
}

// =====================================================
// การเปรียบเทียบ snapshot
// =====================================================

/**
 * ทำดัชนีแถวด้วย id เพื่อเทียบข้าม snapshot
 *
 * รับ: rows = array ของแถวจาก snapshot
 * คืน: Map<string, object> จาก id (เป็น string) ไปยังแถว
 * ผลข้างเคียง: ไม่มี
 */
function rowsById(rows) {
    const map = new Map();
    for (const row of rows) {
        map.set(String(row.id), row);
    }
    return map;
}

/**
 * แสดงค่าให้อ่านง่ายในรายงาน mismatch
 *
 * รับ: value = ค่าจาก PDO (string/int/float/null)
 * คืน: string โดย null เป็น NULL และ string ถูกใส่ quote
 * ผลข้างเคียง: ไม่มี
 */
function show(value) {
    if (value === null) return 'NULL';
    if (typeof value === 'string') return JSON.stringify(value);
    return String(value);
}

/**
 * ตรวจว่า every ค่าเดิมยังอยู่ครบใน after1
 *
 * รับ: before, after1 = snapshot ก่อนและหลังรัน migration
 * คืน: { mismatches: string[], newItems: string[], tables, rows, columns }
 * ผลข้างเคียง: ไม่มี (อ่านอย่างเดียว)
 */
function compareKept(before, after1) {
    const mismatches = [];
    const newItems = [];
    let tables = 0;
    let rows = 0;
    let columns = 0;

    for (const [table, beforeRows] of Object.entries(before.tables)) {
        const afterRows = after1.tables[table];
        if (!Array.isArray(afterRows)) {
            mismatches.push(`${table}: ตารางหายไปใน after1`);
            continue;
        }
        tables++;

        const afterById = rowsById(afterRows);
        for (const row of beforeRows) {
            rows++;
            const next = afterById.get(String(row.id));
            if (next === undefined) {
                mismatches.push(`${table}#${row.id}: แถวหายไปใน after1`);
                continue;
            }
            for (const [column, value] of Object.entries(row)) {
                columns++;
                if (!Object.hasOwn(next, column)) {
                    mismatches.push(`${table}#${row.id} ${column}: ${show(value)} → (คอลัมน์หายไป)`);
                } else if (next[column] !== value) {
                    mismatches.push(`${table}#${row.id} ${column}: ${show(value)} → ${show(next[column])}`);
                }
            }
        }
    }

    for (const [table, afterRows] of Object.entries(after1.tables)) {
        if (!Array.isArray(before.tables[table])) {
            newItems.push(`ตาราง ${table}`);
            continue;
        }
        const known = new Set();
        for (const row of before.tables[table]) {
            for (const column of Object.keys(row)) known.add(column);
        }
        for (const column of Object.keys(afterRows[0] ?? {})) {
            if (!known.has(column)) newItems.push(`คอลัมน์ ${table}.${column}`);
        }
    }

    return { mismatches, newItems, tables, rows, columns };
}

/**
 * ตรวจว่า after1 กับ after2 เหมือนกันทุกตาราง ทุกคอลัมน์ ทุก id
 *
 * รับ: after1, after2 = snapshot สองรอบของการรัน migration
 * คืน: { mismatches: string[], tables, rows }
 * ผลข้างเคียง: ไม่มี (อ่านอย่างเดียว)
 */
function compareRerun(after1, after2) {
    const mismatches = [];
    const tableNames = new Set([...Object.keys(after1.tables), ...Object.keys(after2.tables)]);
    let tables = 0;
    let rows = 0;

    for (const table of tableNames) {
        const rows1 = after1.tables[table];
        const rows2 = after2.tables[table];
        if (!Array.isArray(rows1) || !Array.isArray(rows2)) {
            mismatches.push(`${table}: ตาราง${Array.isArray(rows1) ? 'โผล่ใน after2' : 'หายไปใน after2'}`);
            continue;
        }
        tables++;

        const map1 = rowsById(rows1);
        const map2 = rowsById(rows2);
        for (const [id, row] of map1) {
            rows++;
            const other = map2.get(id);
            if (other === undefined) {
                mismatches.push(`${table}#${id}: แถวหายไปใน after2`);
                continue;
            }
            const columnNames = new Set([...Object.keys(row), ...Object.keys(other)]);
            for (const column of columnNames) {
                if (!Object.hasOwn(other, column)) {
                    mismatches.push(`${table}#${id} ${column}: ${show(row[column])} → (คอลัมน์หายไป)`);
                } else if (!Object.hasOwn(row, column)) {
                    mismatches.push(`${table}#${id} ${column}: (คอลัมน์ใหม่) → ${show(other[column])}`);
                } else if (other[column] !== row[column]) {
                    mismatches.push(`${table}#${id} ${column}: ${show(row[column])} → ${show(other[column])}`);
                }
            }
        }
        for (const id of map2.keys()) {
            if (!map1.has(id)) mismatches.push(`${table}#${id}: แถวใหม่ใน after2`);
        }
    }

    return { mismatches, tables, rows };
}

// =====================================================
// main
// =====================================================

/**
 * ทำงานทั้ง pipeline: คัดลอก, snapshot, migrate, เปรียบเทียบ, รายงาน
 *
 * รับ: ไม่มี (อ่าน process.argv)
 * คืน: ไม่มี (ตั้ง process.exitCode 0 = PASS, 1 = FAIL)
 * ผลข้างเคียง: เขียนไฟล์ snapshot, สร้าง/ลบ dayflow_replay ตามตัวเลือก
 */
function main() {
    const options = parseArgs(process.argv.slice(2));

    if (SOURCE_DB === REPLAY_DB) {
        throw new OperationalError(`ชื่อฐานข้อมูลต้นทางและรีเพลย์ซ้ำกัน (${SOURCE_DB})`);
    }

    let touched = false;
    try {
        console.log(`คัดลอก ${SOURCE_DB} → ${REPLAY_DB}`);
        touched = true;
        copyDatabase();

        const before = takeSnapshot('before');
        console.log(`snapshot before: ${Object.keys(before.tables).length} ตาราง`);

        runMigrations();
        const after1 = takeSnapshot('after1');
        console.log(`snapshot after1: ${Object.keys(after1.tables).length} ตาราง`);

        if (options.injectFault) {
            injectFault();
        }

        runMigrations(['--force']);
        const after2 = takeSnapshot('after2');
        console.log(`snapshot after2: ${Object.keys(after2.tables).length} ตาราง`);

        const kept = compareKept(before, after1);
        const rerun = compareRerun(after1, after2);
        const failed = kept.mismatches.length + rerun.mismatches.length > 0;

        const outDir = options.out ? path.resolve(options.out) : mkdtempSync(path.join(tmpdir(), 'dayflow-replay-'));
        mkdirSync(outDir, { recursive: true });
        for (const [name, snapshot] of [['before', before], ['after1', after1], ['after2', after2]]) {
            writeFileSync(path.join(outDir, `${name}.json`), JSON.stringify(snapshot, null, 2) + '\n');
        }
        console.log(`snapshots: ${outDir}`);

        console.log('');
        console.log(`Kept values (before → after1): ${kept.tables} ตาราง, ${kept.rows} แถว, ${kept.columns} คอลัมน์`);
        if (kept.newItems.length > 0) {
            console.log(`  new: ${kept.newItems.join(', ')}`);
        }
        console.log(`Rerun is a no-op (after1 → after2): ${rerun.tables} ตาราง, ${rerun.rows} แถว`);

        const allMismatches = [
            ...kept.mismatches.map((line) => `${line} [kept]`),
            ...rerun.mismatches.map((line) => `${line} [rerun]`),
        ];
        console.log(`mismatches: ${allMismatches.length}`);
        for (const line of allMismatches.slice(0, MAX_MISMATCHES)) {
            console.log(`  ${line}`);
        }
        if (allMismatches.length > MAX_MISMATCHES) {
            console.log(`  ... และอีก ${allMismatches.length - MAX_MISMATCHES} รายการ`);
        }

        console.log(failed ? 'RESULT: FAIL' : 'RESULT: PASS');
        process.exitCode = failed ? 1 : 0;
    } finally {
        if (touched && !options.keep) {
            try {
                dropReplayDatabase();
            } catch (error) {
                console.error(`WARNING: ลบ ${REPLAY_DB} ไม่สำเร็จ: ${error.message}`);
            }
        } else if (touched) {
            console.log(`เก็บ ${REPLAY_DB} ไว้ตาม --keep`);
        }
    }
}

try {
    main();
} catch (error) {
    if (error instanceof OperationalError) {
        console.error(`ERROR: ${error.message}`);
        process.exitCode = 2;
    } else {
        throw error;
    }
}
