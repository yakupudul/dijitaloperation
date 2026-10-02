import path from 'node:path';
import { ARTIFACTS_DIR, readJson, writeJson } from './env.js';

export const VERDICTS_FILE = path.join(ARTIFACTS_DIR, 'qa002-verdicts.json');

/**
 * @param {string} key
 * @param {'PASS' | 'PARTIAL' | 'FAIL' | 'TRUTHFUL_EMPTY'} status
 * @param {string} [note]
 */
export function setVerdict(key, status, note = '') {
    const current = readJson(VERDICTS_FILE, {});
    current[key] = {
        status,
        note,
        at: new Date().toISOString(),
    };
    writeJson(VERDICTS_FILE, current);

    return current[key];
}

export function readVerdicts() {
    return readJson(VERDICTS_FILE, {});
}
