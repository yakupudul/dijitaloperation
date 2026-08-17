export const PRODUCT_ALLOWLIST = [
    'Google Ads',
    'Meta Ads',
    'GA4',
    'Search Console',
    'Google Search Console',
    'Google Analytics',
    'Google Business Profile',
    'DataForSEO',
    'OpenAI',
    'Anthropic',
    'Gemini',
    'OAuth',
    'CPC',
    'CTR',
    'URL',
    'API',
    'CMS',
    'GBP',
    'GSC',
    'SEO',
    'CRM',
    'SMTP',
    'MoxDOP',
    'Moximu',
    'Instagram',
    'WordPress',
    'QA Final',
];

export const CONFIRMED_TR_LEAKAGE = [
    'Open brands',
    'Review findings',
    'INDUSTRY',
    'BRANDS',
    'OPEN TASKS',
    'STATUS',
    'Open',
    'Back',
    'Search',
    'Digital Assets',
    'New Customer setup',
    'Customer name',
    'Needs Attention',
    'Responsible',
    'WORK',
    'Portfolio',
    'Configure',
    'Managed Assets',
    'Data Stale / Unavailable',
    'Assets with Active Work',
    'Needs Attention',
    'Data Issues',
    'Active Work',
    'Recently Updated',
    'Estate Matrix',
    'Asset Type',
    'Operational Status',
    'Data State',
];

export const ENGLISH_CHROME = [
    'Dashboard',
    'Customers',
    'Brands',
    'Digital Assets',
    'Files',
    'Opportunities',
    'Findings',
    'Recommendations',
    'Work',
    'Activity',
    'Integrations',
    'Settings',
    'Open brands',
    'Review findings',
    'Quick add',
    'New Customer setup',
    'Customer profile',
    'Open Files',
    'View Activity',
    'Open Work',
    'Edit customer',
    'Add brand',
    'Add contact',
    'Save customer',
    'Save brand',
    'Cancel',
    'Status',
    'Industry',
    'Type',
    'HQ country',
    'Responsible',
    'Attention',
    'Open',
    'Back',
    'Configure',
    'Save digital asset',
    'Asset identity',
    'Legacy free-text fields',
    'Ownership',
    'Target markets',
    'Public Discovery',
    'Refresh public observations',
    'Customer name',
    'Legal name',
    'Sign in',
    'Email',
    'Password',
    'Needs attention',
    'Digital assets',
    'Open findings',
    'Open tasks',
    'Primary communication',
    'Account Owner',
    'View all brands',
    'Active filters',
    'Search…',
    'No matches',
    'Saving…',
    'More actions',
    'Archive customer',
    'Restore customer',
    'Customer Setup',
    'Portfolio',
    'General',
    'Team & Access',
    'Notifications',
    'Operations',
    'AI & Intelligence',
    'Advanced',
    'Save general settings',
    'Not configured',
    'Defined',
    'Not collected',
    'Website details',
    'Connection',
    'Brand workspace',
    'Overview',
    'Business',
    'Digital Estate',
    'Growth',
    'Value',
    'Context',
    'Observed Facts',
    'Candidates',
    'Conflicts',
    'Sources & History',
    'Add operator',
    'Deactivate access',
    'Team Member',
    'Prompt',
    'Milestone',
];

const TR_CHARS = /[ğüşöçıİĞÜŞÖÇ]/;

function allowed(text) {
    return PRODUCT_ALLOWLIST.some((token) => text.includes(token));
}

function looksLikeName(text) {
    return /^E2E /.test(text) || /@/.test(text) || /^\d+$/.test(text);
}

/**
 * @param {import('@playwright/test').Page} page
 */
export async function collectChrome(page) {
    return page.evaluate(() => {
        const selectors = [
            'h1', 'h2', 'h3', 'h4',
            'button', 'a', 'label', 'th', 'dt',
            'nav', '[role="tab"]', '[role="heading"]',
            '[role="button"]', 'caption',
            'p.text-xs', 'p.text-sm',
        ];
        const rows = [];
        const seen = new Set();

        for (const selector of selectors) {
            for (const el of document.querySelectorAll(selector)) {
                if (el.closest('#operator-sidebar') && el.tagName === 'A') {
                    // keep sidebar labels
                }
                const text = (el.innerText || '').replace(/\s+/g, ' ').trim();
                if (!text || text.length > 120) {
                    continue;
                }
                const key = `${el.tagName}:${text}`;
                if (seen.has(key)) {
                    continue;
                }
                seen.add(key);
                rows.push({
                    text,
                    tag: el.tagName.toLowerCase(),
                    role: el.getAttribute('role') || el.tagName.toLowerCase(),
                    sidebar: Boolean(el.closest('#operator-sidebar')),
                });
            }
        }

        return rows;
    });
}

/**
 * @param {Array<{text: string, tag: string, role: string}>} rows
 * @param {string} route
 */
export function findEnglishLeakage(rows, route) {
    const hits = [];

    for (const row of rows) {
        if (allowed(row.text) || looksLikeName(row.text)) {
            continue;
        }

        const exact = ENGLISH_CHROME.find((chrome) => chrome.toLowerCase() === row.text.toLowerCase());
        if (exact) {
            hits.push({ route, visibleText: row.text, role: row.role, tag: row.tag, matched: exact });
            continue;
        }

        if (/^(Open|Save|Edit|Cancel|Back|Search|Status|Type|Industry|Configure|Review)(\s|$)/i.test(row.text)
            && !TR_CHARS.test(row.text)
            && !allowed(row.text)) {
            hits.push({ route, visibleText: row.text, role: row.role, tag: row.tag, matched: 'english-verb-pattern' });
        }
    }

    return hits;
}

/**
 * @param {Array<{text: string, tag: string, role: string}>} rows
 * @param {string} route
 */
export function findTurkishLeakage(rows, route) {
    const hits = [];

    for (const row of rows) {
        if (allowed(row.text) || looksLikeName(row.text)) {
            continue;
        }
        if (/Türkiye|Türkçe/.test(row.text) && row.text.length < 20) {
            continue;
        }
        if (TR_CHARS.test(row.text) || /^(Müşteri|Marka|Ayarlar|Kontrol Paneli|Entegrasyon|Bulgular|Öneriler)/i.test(row.text)) {
            hits.push({ route, visibleText: row.text, role: row.role, tag: row.tag });
        }
    }

    return hits;
}
