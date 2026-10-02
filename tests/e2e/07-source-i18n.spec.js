import { test } from '@playwright/test';
import { spawnSync } from 'node:child_process';
import path from 'node:path';
import { WORKSPACE } from './helpers/env.js';

test('scan operator source for hard-coded product copy', () => {
    const script = path.join(WORKSPACE, 'tests/e2e/scripts/scan-hardcoded-copy.mjs');
    const result = spawnSync(process.execPath, [script], {
        cwd: WORKSPACE,
        encoding: 'utf8',
        env: process.env,
    });

    if (result.status !== 0) {
        throw new Error(result.stderr || result.stdout || `scanner exited ${result.status}`);
    }
});
