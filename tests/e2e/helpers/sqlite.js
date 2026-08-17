import { execFileSync } from 'node:child_process';
import { E2E_DATABASE } from './env.js';

export function sqliteJson(sql) {
    const raw = execFileSync('sqlite3', ['-json', E2E_DATABASE, sql], { encoding: 'utf8' }).trim();
    if (raw === '') {
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

function escapeSql(value) {
    return String(value).replaceAll("'", "''");
}
