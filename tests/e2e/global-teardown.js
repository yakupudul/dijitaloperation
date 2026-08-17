import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { WORKSPACE } from './helpers/env.js';

export default async function globalTeardown() {
    const script = path.join(WORKSPACE, 'tests/e2e/scripts/generate-report.mjs');
    const result = spawnSync(process.execPath, [script], {
        cwd: WORKSPACE,
        stdio: 'inherit',
        env: process.env,
    });

    if (result.status !== 0) {
        throw new Error(`QA report generator exited ${result.status}`);
    }
}
