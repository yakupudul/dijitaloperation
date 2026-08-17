import { execFileSync } from 'node:child_process';
import { E2E_DATABASE } from './env.js';

export function sqliteJson(sql) {
    const php = [
        '$db = new PDO("sqlite:" . getenv("MOXDOP_E2E_DATABASE"));',
        '$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);',
        '$stmt = $db->query(' + phpString(sql) + ');',
        'echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));',
    ].join('\n');

    const raw = execFileSync('php', ['-r', php], {
        encoding: 'utf8',
        env: { ...process.env, MOXDOP_E2E_DATABASE: E2E_DATABASE },
    }).trim();

    if (raw === '' || raw === '[]') {
        return [];
    }

    return JSON.parse(raw);
}

export function customerByName(name) {
    const rows = sqliteJson(
        `select id, name, legal_name, hq_country, hq_city, status, type from customers where name = '${escapeSql(name)}' limit 1`,
    );

    return rows[0] || null;
}

export function brandByName(name) {
    const rows = sqliteJson(
        `select id, name, customer_id, primary_country from brands where name = '${escapeSql(name)}' limit 1`,
    );

    return rows[0] || null;
}

export function assetsForBrand(brandId) {
    return sqliteJson(
        `select id, name, type, status, brand_id from digital_assets where brand_id = ${Number(brandId)} order by id`,
    );
}

export function userByEmail(email) {
    const rows = sqliteJson(
        `select id, name, email, is_active, locale from users where email = '${escapeSql(email)}' limit 1`,
    );

    return rows[0] || null;
}

export function taskByTitle(title) {
    try {
        const rows = sqliteJson(
            `select id, title, customer_id, brand_id, status, assignee_id from tasks where title = '${escapeSql(title)}' limit 1`,
        );

        return rows[0] || null;
    } catch {
        return null;
    }
}

export function fileByOriginalName(name) {
    try {
        const rows = sqliteJson(
            `select id, uuid, original_name, scope_type, customer_id, brand_id, path from operator_files where original_name = '${escapeSql(name)}' order by id desc limit 1`,
        );

        return rows[0] || null;
    } catch {
        return null;
    }
}

export function clientRequestByTitle(title) {
    try {
        const rows = sqliteJson(
            `select id, title, customer_id, brand_id, status from client_requests where title = '${escapeSql(title)}' limit 1`,
        );

        return rows[0] || null;
    } catch {
        return null;
    }
}

export function countRows(table) {
    try {
        const rows = sqliteJson(`select count(*) as c from ${table}`);

        return Number(rows[0]?.c || 0);
    } catch {
        return -1;
    }
}

function escapeSql(value) {
    return String(value).replaceAll("'", "''");
}

function phpString(value) {
    return "'" + String(value).replaceAll('\\', '\\\\').replaceAll("'", "\\'") + "'";
}
