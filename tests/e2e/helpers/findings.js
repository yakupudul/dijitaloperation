import { appendJsonl, FINDINGS_FILE } from './env.js';

let sequence = 0;

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
    sequence += 1;
    const id = finding.id || `QA-E2E-${String(sequence).padStart(3, '0')}`;
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
