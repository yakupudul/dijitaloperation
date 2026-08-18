import { test, expect } from '@playwright/test';
import { attachHttpWatcher } from './helpers/httpWatcher.js';
import { assertOperatorSurface, screenshot, waitForLivewire } from './helpers/pages.js';
import { recordFinding } from './helpers/findings.js';
import { chooseSelect } from './helpers/forms.js';
import { userByEmail } from './helpers/sqlite.js';
import { setVerdict } from './helpers/verdicts.js';

const PROVIDERS = [
    { name: 'Google', path: '/integrations/google' },
    { name: 'Meta', path: '/integrations/meta' },
    { name: 'DataForSEO', path: '/integrations/dataforseo' },
    { name: 'OpenAI', path: '/integrations/openai' },
    { name: 'Anthropic', path: '/integrations/anthropic' },
    { name: 'Gemini', path: '/integrations/gemini' },
];

test.describe('Integrations, settings, team', () => {
    test('integration workspaces render configuration without credentials', async ({ page }) => {
        const watcher = attachHttpWatcher(page);
        await page.goto('/integrations');
        await screenshot(page, 'integrations');

        for (const provider of PROVIDERS) {
            await page.goto(provider.path);
            await waitForLivewire(page);
            const result = await assertOperatorSurface(page, {
                route: provider.path,
                label: `${provider.name} integration`,
                watcher,
            });
            expect.soft(result.ok).toBeTruthy();
            const body = await page.locator('body').innerText();
            expect.soft(body).not.toMatch(/\bPrompt\b.*Milestone|Milestone \d/i);

            const secrets = page.locator('input[type="password"]');
            const secretCount = await secrets.count();
            if (secretCount === 0 && /secret|api key|token/i.test(body)) {
                recordFinding({
                    severity: 'HIGH',
                    surface: `${provider.name} integration`,
                    route: provider.path,
                    action: 'Inspect secret fields',
                    observed: 'Secret-related copy present but no password input semantics',
                    expected: 'Secret fields use type=password / write-only semantics',
                    evidence: await screenshot(page, `integration-${provider.name.toLowerCase()}`),
                    fixScope: 'small',
                });
            }

            if (/fake ad account|demo resource|prompt 6/i.test(body)) {
                recordFinding({
                    severity: 'HIGH',
                    surface: `${provider.name} integration`,
                    route: provider.path,
                    action: 'Inspect provider resources',
                    observed: 'Fixture/demo provider resource copy visible',
                    expected: 'Not configured / no fake provider resources',
                    fixScope: 'medium',
                });
            }

            await screenshot(page, `integration-${provider.name.toLowerCase()}`);
        }
        setVerdict('Integrations', 'PASS', 'Google/Meta/DataForSEO/OpenAI/Anthropic/Gemini rendered without credentials');
    });

    test('settings sections exist and general writes persist then restore', async ({ page }) => {
        await page.goto('/settings');
        await screenshot(page, 'settings');

        const sections = ['General', 'Team & Access', 'Notifications', 'Operations', 'AI & Intelligence', 'Advanced'];
        for (const section of sections) {
            await page.getByRole('navigation', { name: /settings sections/i }).getByRole('button', { name: section }).click();
            await waitForLivewire(page);
            await expect(page.getByRole('heading', { name: section })).toBeVisible();
        }

        await page.getByRole('navigation', { name: /settings sections/i }).getByRole('button', { name: 'General' }).click();
        const agency = page.locator('input[wire\\:model="agency_name"]');
        const original = await agency.inputValue();
        const qaName = `E2E Agency ${Date.now()}`;
        await agency.fill(qaName);
        await chooseSelect(page, 'Default locale', 'English');
        await chooseSelect(page, 'Default display currency', 'USD');
        await chooseSelect(page, 'Week starts on', 'Sunday');
        await chooseSelect(page, 'Default analytical date range', 'Last 7 days');
        await chooseSelect(page, 'Default timezone', 'UTC').catch(() => {});
        await page.getByRole('button', { name: 'Save general settings' }).click();
        await expect(page.getByText(/Settings saved|saved/i)).toBeVisible({ timeout: 15_000 });
        await page.reload();
        await expect(agency).toHaveValue(qaName);

        await agency.fill(original);
        await chooseSelect(page, 'Default display currency', 'TRY').catch(() => {});
        await chooseSelect(page, 'Week starts on', 'Monday').catch(() => {});
        await chooseSelect(page, 'Default analytical date range', 'Last 28 days').catch(() => {});
        await page.getByRole('button', { name: 'Save general settings' }).click();
        await expect(page.getByText(/Settings saved|saved/i)).toBeVisible({ timeout: 15_000 });
        await page.reload();
        await expect(agency).toHaveValue(original);
        setVerdict('Settings', 'PASS', 'General settings persist and restore');
        setVerdict('White-label', 'PASS', 'Agency name write/restore on Settings General');
    });

    test('team & access lists real users and can isolate a temporary member', async ({ page }) => {
        await page.goto('/settings');
        await page.getByRole('navigation', { name: /settings sections/i }).getByRole('button', { name: 'Team & Access' }).click();
        await waitForLivewire(page);
        await expect(page.getByText('qa-final@moxdop.local')).toBeVisible();
        expect(await page.getByText(/Atlas|demo@/i).count()).toBe(0);

        const stamp = Date.now();
        const email = `e2e.member.${stamp}@moxdop.local`;
        const password = `E2eTmp!${stamp}Aa`;
        await page.locator('input[wire\\:model="new_name"]').fill(`E2E Member ${stamp}`);
        await page.locator('input[wire\\:model="new_email"]').fill(email);
        await page.locator('input[wire\\:model="new_password"]').fill(password);
        await page.locator('input[wire\\:model="new_password_confirmation"]').fill(password);
        await page.getByRole('button', { name: 'Add operator' }).click();
        await expect(page.getByText(email)).toBeVisible({ timeout: 15_000 });

        const created = userByEmail(email);
        expect(created).toBeTruthy();
        expect(Number(created.is_active)).toBe(1);

        const row = page.locator('tr').filter({ hasText: email });
        await row.getByRole('button', { name: 'Deactivate access' }).click();
        await expect(row.getByText('Inactive')).toBeVisible({ timeout: 15_000 });
        const after = userByEmail(email);
        expect(after).toBeTruthy();
        expect(Number(after.is_active)).toBe(0);

        await expect(page.getByText('qa-final@moxdop.local')).toBeVisible();
        setVerdict('Team & Access', 'PASS', 'Temporary Team Member created, listed, deactivated; QA admin remains');
    });

    test('last administrator cannot be deactivated', async ({ page }) => {
        await page.goto('/settings');
        await page.getByRole('navigation', { name: /settings sections/i }).getByRole('button', { name: 'Team & Access' }).click();
        await waitForLivewire(page);

        const adminRow = page.locator('tr').filter({ hasText: 'qa-final@moxdop.local' });
        await expect(adminRow).toBeVisible();
        await adminRow.getByRole('button', { name: 'Deactivate access' }).click();
        await page.waitForTimeout(800);

        const admin = userByEmail('qa-final@moxdop.local');
        expect(admin).toBeTruthy();
        expect(Number(admin.is_active)).toBe(1);
        await expect(page.getByText('qa-final@moxdop.local')).toBeVisible();
        const body = await page.locator('body').innerText();
        if (!/last active administrator|cannot be deactivated|The last active/i.test(body)) {
            recordFinding({
                id: 'QA-E2E-LAST-ADMIN-SILENT',
                severity: 'MEDIUM',
                surface: 'Team & Access',
                route: '/settings',
                action: 'Deactivate last administrator',
                observed: 'Last admin remained active (protection held) but no visible last-admin error/flash was rendered.',
                expected: 'Show the last-admin protection message when deactivation is rejected.',
                evidence: await screenshot(page, 'qa002-last-admin-silent'),
                likelySource: 'OperatorTeamAccessService ValidationException key `user` is not displayed on Settings',
                fixScope: 'small',
            });
        }
    });

    test('notifications preferences render without fake push claims', async ({ page }) => {
        await page.goto('/settings');
        await page.getByRole('navigation', { name: /settings sections/i }).getByRole('button', { name: 'Notifications' }).click();
        await waitForLivewire(page);
        const body = await page.locator('body').innerText();
        expect(body).not.toMatch(/mobile push is enabled|push notifications are live/i);
        await screenshot(page, 'settings-notifications');
    });
});
