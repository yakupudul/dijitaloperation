import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { WORKSPACE } from './helpers/env.js';

export default async function globalTeardown() {
    for (const name of ['generate-report.mjs', 'generate-report-002.mjs']) {
        const script = path.join(WORKSPACE, 'tests/e2e/scripts', name);
        const result = spawnSync(process.execPath, [script], {
            cwd: WORKSPACE,
            stdio: 'inherit',
            env: process.env,
        });

        if (result.status !== 0) {
            throw new Error(`QA report generator ${name} exited ${result.status}`);
        }
    }
}
