import fs from 'node:fs';
import path from 'node:path';
import { SOURCE_I18N_FILE, WORKSPACE, writeJson } from '../helpers/env.js';

const ROOTS = [
    'resources/views/livewire/demo',
    'resources/views/operator',
    'app/Livewire/Demo',
];

const SKIP_DIRS = new Set(['vendor', 'node_modules', 'storage']);
const PROVIDER = /Google Ads|Meta Ads|GA4|Search Console|DataForSEO|OpenAI|Anthropic|Gemini|OAuth|WordPress|Instagram/;
const TRANSLATED = /__\(|@lang\(|trans\(/;

function walk(dir, acc = []) {
    if (!fs.existsSync(dir)) {
        return acc;
    }

    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
        if (SKIP_DIRS.has(entry.name)) {
            continue;
        }
        const full = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            walk(full, acc);
        } else if (/\.(php|blade\.php)$/.test(entry.name)) {
            acc.push(full);
        }
    }

    return acc;
}

function classify(text, line) {
    if (TRANSLATED.test(line)) {
        return 'TRANSLATION_RESOURCE';
    }
    if (PROVIDER.test(text)) {
        return 'PROVIDER_DATA';
    }
    if (/\$[a-zA-Z_]/.test(text) || /\{\{/.test(line)) {
        return 'DYNAMIC_DATA';
    }
    if (/wire:|class=|route\(|Livewire|DemoState|TODO|FIXME/.test(text)) {
        return 'INTERNAL_TECH_COPY';
    }

    return 'HARD_CODED_PRODUCT_COPY';
}

function scanFile(file) {
    const rel = path.relative(WORKSPACE, file);
    const lines = fs.readFileSync(file, 'utf8').split('\n');
    const hits = [];

    lines.forEach((line, index) => {
        if (TRANSLATED.test(line)) {
            return;
        }

        const bladeText = [...line.matchAll(/>([A-Z][^<{]{2,90})</g)];
        const attrLabel = [...line.matchAll(/\b(?:label|title|placeholder|aria-label)="([A-Z][^"]{2,90})"/g)];
        const phpString = file.endsWith('.php') && !file.endsWith('.blade.php')
            ? [...line.matchAll(/'([A-Z][A-Za-z0-9][^']{2,80})'/g)]
            : [];

        for (const match of [...bladeText, ...attrLabel, ...phpString]) {
            const text = match[1].trim();
            if (text.length < 3 || text.length > 90) {
                continue;
            }
            if (/^[A-Z_]+$/.test(text)) {
                continue;
            }
            const kind = classify(text, line);
            if (kind !== 'HARD_CODED_PRODUCT_COPY') {
                continue;
            }
            hits.push({
                file: rel,
                line: index + 1,
                text,
                kind,
                context: line.trim().slice(0, 180),
            });
        }
    });

    return hits;
}

const files = ROOTS.flatMap((root) => walk(path.join(WORKSPACE, root)));
const items = files.flatMap(scanFile);
const unique = [];
const seen = new Set();
for (const item of items) {
    const key = `${item.file}:${item.text}`;
    if (seen.has(key)) {
        continue;
    }
    seen.add(key);
    unique.push(item);
}

writeJson(SOURCE_I18N_FILE, {
    scannedFiles: files.length,
    hardCodedCount: unique.length,
    items: unique.slice(0, 400),
});

process.stdout.write(`hard-coded product copy candidates: ${unique.length}\n`);
