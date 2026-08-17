import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

export const WORKSPACE = '/workspace';
export const ARTIFACTS_DIR = path.join(WORKSPACE, '.qa-artifacts');
export const AUTH_STATE = path.join(ARTIFACTS_DIR, 'auth.json');
export const FINDINGS_FILE = path.join(ARTIFACTS_DIR, 'findings.jsonl');
export const IDENTITY_FILE = path.join(ARTIFACTS_DIR, 'identity.json');
export const SESSION_FILE = path.join(ARTIFACTS_DIR, 'session.json');
export const SCREENSHOTS_DIR = path.join(ARTIFACTS_DIR, 'screenshots');
export const I18N_FILE = path.join(ARTIFACTS_DIR, 'i18n-inventory.json');
export const SOURCE_I18N_FILE = path.join(ARTIFACTS_DIR, 'source-i18n-inventory.json');
export const WATCHER_FILE = path.join(ARTIFACTS_DIR, 'http-watcher.jsonl');

export const BASE_URL = process.env.MOXDOP_E2E_BASE_URL || 'http://127.0.0.1:8012';
export const E2E_EMAIL = process.env.MOXDOP_E2E_EMAIL || 'qa-final@moxdop.local';
export const E2E_DATABASE = process.env.MOXDOP_E2E_DATABASE || '/tmp/moxdop-final-manual-qa.sqlite';
export const PASSWORD_FILE = process.env.MOXDOP_E2E_PASSWORD_FILE || '/tmp/moxdop-final-manual-qa-admin.secret';

export function ensureArtifactsDir() {
    fs.mkdirSync(ARTIFACTS_DIR, { recursive: true });
    fs.mkdirSync(SCREENSHOTS_DIR, { recursive: true });
}

export function loadPassword() {
    if (process.env.MOXDOP_E2E_PASSWORD) {
        return process.env.MOXDOP_E2E_PASSWORD;
    }

    if (!fs.existsSync(PASSWORD_FILE)) {
        throw new Error(`E2E password source missing: ${PASSWORD_FILE} (or set MOXDOP_E2E_PASSWORD)`);
    }

    return fs.readFileSync(PASSWORD_FILE, 'utf8').trim();
}

export function gitIdentity() {
    const run = (args) => execFileSync('git', args, { cwd: WORKSPACE, encoding: 'utf8' }).trim();

    return {
        workspace: WORKSPACE,
        toplevel: run(['rev-parse', '--show-toplevel']),
        branch: run(['branch', '--show-current']),
        head: run(['rev-parse', 'HEAD']),
        origin: run(['remote', 'get-url', 'origin']).replace(/x-access-token:[^@]+@/i, 'github.com/'),
    };
}

export function writeJson(file, data) {
    ensureArtifactsDir();
    fs.writeFileSync(file, JSON.stringify(data, null, 2) + '\n');
}

export function readJson(file, fallback = null) {
    if (!fs.existsSync(file)) {
        return fallback;
    }

    return JSON.parse(fs.readFileSync(file, 'utf8'));
}

export function appendJsonl(file, record) {
    ensureArtifactsDir();
    fs.appendFileSync(file, JSON.stringify(record) + '\n');
}
