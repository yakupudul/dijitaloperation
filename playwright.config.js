import { defineConfig, devices } from '@playwright/test';
import {
    ARTIFACTS_DIR,
    AUTH_STATE,
    BASE_URL,
    ensureArtifactsDir,
} from './tests/e2e/helpers/env.js';

ensureArtifactsDir();

/**
 * MoxDOP operator browser QA harness.
 *
 * Prefer an already-running isolated QA server (default http://127.0.0.1:8013
 * against /tmp/moxdop-final-manual-qa.sqlite). Playwright will not start a
 * second application server against the workspace default database.
 */
export default defineConfig({
    testDir: './tests/e2e',
    fullyParallel: false,
    workers: 1,
    forbidOnly: !!process.env.CI,
    retries: 0,
    timeout: 90_000,
    expect: { timeout: 10_000 },
    reporter: [
        ['list'],
        ['html', { outputFolder: 'playwright-report', open: 'never' }],
        ['json', { outputFile: `${ARTIFACTS_DIR}/playwright-results.json` }],
    ],
    globalSetup: './tests/e2e/global-setup.js',
    globalTeardown: './tests/e2e/global-teardown.js',
    use: {
        baseURL: BASE_URL,
        browserName: 'chromium',
        viewport: { width: 1440, height: 900 },
        locale: 'en-US',
        screenshot: { mode: 'only-on-failure', fullPage: true },
        trace: 'retain-on-failure',
        video: 'off',
        actionTimeout: 15_000,
        navigationTimeout: 30_000,
        ignoreHTTPSErrors: true,
    },
    webServer: {
        command: 'bash tests/e2e/scripts/serve-isolated.sh',
        url: `${BASE_URL}/login`,
        reuseExistingServer: true,
        timeout: 30_000,
        stdout: 'pipe',
        stderr: 'pipe',
    },
    projects: [
        {
            name: 'setup',
            testMatch: /auth\.setup\.js/,
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
                trace: 'off',
                screenshot: 'off',
            },
        },
        {
            name: 'chromium',
            dependencies: ['setup'],
            testIgnore: /auth\.setup\.js/,
            use: {
                ...devices['Desktop Chrome'],
                viewport: { width: 1440, height: 900 },
                storageState: AUTH_STATE,
            },
        },
    ],
});
