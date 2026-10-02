import fs from 'node:fs';
import { appendJsonl, FINDINGS_FILE } from './env.js';

function nextSequence() {
    if (!fs.existsSync(FINDINGS_FILE)) {
        return 1;
    }

    return fs.readFileSync(FINDINGS_FILE, 'utf8').split('\n').filter(Boolean).length + 1;
}

/**
 * @param {{
 *   id?: string,
 *   severity: 'BLOCKER' | 'HIGH' | 'MEDIUM' | 'LOW',
 *   surface: string,
 *   route?: string,
 *   action?: string,
 *   observed: string,
 *   expected: string,
 *   automated?: boolean,
 *   evidence?: string,
 *   likelySource?: string,
 *   fixScope?: 'small' | 'medium' | 'large',
 *   manualId?: string,
 * }} finding
 */
export function recordFinding(finding) {
    const id = finding.id || `QA-E2E-${String(nextSequence()).padStart(3, '0')}`;
    const row = {
        id,
        severity: finding.severity,
        surface: finding.surface,
        route: finding.route || '',
        action: finding.action || '',
        observed: finding.observed,
        expected: finding.expected,
        automated: finding.automated !== false,
        evidence: finding.evidence || '',
        likelySource: finding.likelySource || '',
        fixScope: finding.fixScope || 'small',
        manualId: finding.manualId || '',
        recordedAt: new Date().toISOString(),
    };

    appendJsonl(FINDINGS_FILE, row);

    return row;
}
