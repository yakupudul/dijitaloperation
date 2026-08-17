import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import {
    ARTIFACTS_DIR,
    AUTH_STATE,
    BASE_URL,
    E2E_DATABASE,
    E2E_EMAIL,
    FINDINGS_FILE,
    IDENTITY_FILE,
    I18N_FILE,
    PASSWORD_FILE,
    SCREENSHOTS_DIR,
    SESSION_FILE,
    SOURCE_I18N_FILE,
    WATCHER_FILE,
    WORKSPACE,
    ensureArtifactsDir,
    gitIdentity,
    writeJson,
} from './helpers/env.js';
import { VERDICTS_FILE } from './helpers/verdicts.js';

export default async function globalSetup() {
    ensureArtifactsDir();

    for (const file of [FINDINGS_FILE, WATCHER_FILE, I18N_FILE, SOURCE_I18N_FILE, SESSION_FILE, VERDICTS_FILE]) {
        if (fs.existsSync(file)) {
            fs.rmSync(file);
        }
    }

    const git = gitIdentity();

    if (git.toplevel !== WORKSPACE) {
        throw new Error(`Refusing to run E2E outside ${WORKSPACE} (toplevel=${git.toplevel})`);
    }

    if (git.branch !== 'cursor/production-readiness-audit-ea01') {
        throw new Error(`Unexpected branch ${git.branch}`);
    }

    const login = await fetch(`${BASE_URL}/app/login`);
    if (!login.ok) {
        throw new Error(`QA server not reachable at ${BASE_URL}/app/login (HTTP ${login.status})`);
    }

    execFileSync('php', ['tests/e2e/scripts/ensure-qa-admin.php'], {
        cwd: WORKSPACE,
        stdio: 'inherit',
        env: {
            ...process.env,
            DB_CONNECTION: 'sqlite',
            DB_DATABASE: E2E_DATABASE,
            MOXDOP_E2E_EMAIL: E2E_EMAIL,
            MOXDOP_E2E_PASSWORD_FILE: PASSWORD_FILE,
        },
    });

    writeJson(IDENTITY_FILE, {
        workspace: WORKSPACE,
        git,
        baseURL: BASE_URL,
        database: E2E_DATABASE,
        databaseExists: fs.existsSync(E2E_DATABASE),
        email: E2E_EMAIL,
        passwordSource: process.env.MOXDOP_E2E_PASSWORD ? 'env:MOXDOP_E2E_PASSWORD' : `file:${PASSWORD_FILE}`,
        authState: AUTH_STATE,
        artifacts: ARTIFACTS_DIR,
        screenshots: SCREENSHOTS_DIR,
        startedAt: new Date().toISOString(),
        note: 'Password is never written to this file or to Playwright config.',
    });
}
