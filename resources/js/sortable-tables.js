const tableSortState = new Map();

const NULL_TOKENS = new Set(['', '—', '-', '–', 'n/a', 'na', 'null', 'unavailable', 'yok']);
const ACTION_HEADER = /^(işlem|işlemler|aksiyon|aksiyonlar|action|actions|detay|details)$/i;

function normalizeText(value) {
    return String(value ?? '')
        .replace(/\u00a0/g, ' ')
        .replace(/\s+/g, ' ')
        .trim();
}

function headerLabel(th) {
    const clone = th.cloneNode(true);
    clone.querySelectorAll('[data-sort-indicator]').forEach((node) => node.remove());
    return normalizeText(clone.textContent);
}

function cellText(row, columnIndex) {
    const cell = row.cells?.[columnIndex];
    if (!cell) {
        return '';
    }

    return normalizeText(cell.dataset.sortValue ?? cell.textContent);
}

function isNullValue(value) {
    return NULL_TOKENS.has(normalizeText(value).toLocaleLowerCase(document.documentElement.lang || undefined));
}

function parseNumeric(value) {
    const raw = normalizeText(value);
    if (isNullValue(raw)) {
        return null;
    }

    const stripped = raw
        .replace(/\b(TRY|TL|USD|EUR|GBP|CAD|AUD|JPY|CHF)\b/gi, '')
        .replace(/[₺€£$%x×]/g, '')
        .replace(/\s+/g, '')
        .trim();

    if (!/^[+-]?[\d.,]+$/.test(stripped)) {
        return null;
    }

    const sign = stripped.startsWith('-') ? -1 : 1;
    const unsigned = stripped.replace(/^[+-]/, '');
    const commaCount = (unsigned.match(/,/g) || []).length;
    const dotCount = (unsigned.match(/\./g) || []).length;
    const locale = (document.documentElement.lang || 'tr').toLowerCase();
    let normalized = unsigned;

    if (commaCount > 0 && dotCount > 0) {
        const decimalSeparator = unsigned.lastIndexOf(',') > unsigned.lastIndexOf('.') ? ',' : '.';
        const thousandsSeparator = decimalSeparator === ',' ? '.' : ',';
        normalized = unsigned.split(thousandsSeparator).join('');
        normalized = normalized.replace(decimalSeparator, '.');
    } else if (commaCount > 0) {
        const parts = unsigned.split(',');
        const tail = parts.at(-1) || '';
        const isDecimal = locale.startsWith('tr')
            ? commaCount === 1 && tail.length > 0 && tail.length <= 2
            : commaCount === 1 && tail.length > 0 && tail.length <= 2;
        normalized = isDecimal ? `${parts.slice(0, -1).join('')}.${tail}` : parts.join('');
    } else if (dotCount > 0) {
        const parts = unsigned.split('.');
        const tail = parts.at(-1) || '';
        const isDecimal = locale.startsWith('tr')
            ? dotCount === 1 && tail.length > 0 && tail.length <= 2
            : dotCount === 1 && tail.length > 0 && tail.length <= 2;
        normalized = isDecimal ? `${parts.slice(0, -1).join('')}.${tail}` : parts.join('');
    }

    const parsed = Number(normalized);
    return Number.isFinite(parsed) ? parsed * sign : null;
}

function parseDate(value) {
    const raw = normalizeText(value);
    if (isNullValue(raw)) {
        return null;
    }

    let match = raw.match(/^(\d{4})-(\d{2})-(\d{2})(?:\s|$)/);
    if (match) {
        return Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]));
    }

    match = raw.match(/^(\d{1,2})[./](\d{1,2})[./](\d{4})(?:\s|$)/);
    if (match) {
        return Date.UTC(Number(match[3]), Number(match[2]) - 1, Number(match[1]));
    }

    const timestamp = Date.parse(raw);
    return Number.isNaN(timestamp) ? null : timestamp;
}

function columnRows(table, columnIndex) {
    const rows = [];
    table.tBodies.forEach((tbody) => {
        Array.from(tbody.rows).forEach((row) => {
            if (isSortableRow(row, columnIndex)) {
                rows.push(row);
            }
        });
    });
    return rows;
}

function isSortableRow(row, columnIndex) {
    if (!row || row.dataset.sortFixed === 'true' || row.querySelector('th')) {
        return false;
    }

    if (!row.cells || row.cells.length <= columnIndex) {
        return false;
    }

    return !Array.from(row.cells).some((cell) => Number(cell.colSpan || 1) > 1);
}

function inferColumnType(table, columnIndex, th) {
    const explicit = th.dataset.sortType;
    if (['number', 'date', 'text'].includes(explicit)) {
        return explicit;
    }

    const samples = columnRows(table, columnIndex)
        .map((row) => cellText(row, columnIndex))
        .filter((value) => !isNullValue(value))
        .slice(0, 16);

    if (samples.length === 0) {
        return 'text';
    }

    if (samples.every((value) => parseNumeric(value) !== null)) {
        return 'number';
    }

    if (samples.every((value) => parseDate(value) !== null)) {
        return 'date';
    }

    return 'text';
}

function comparableValue(raw, type) {
    if (isNullValue(raw)) {
        return null;
    }

    if (type === 'number') {
        return parseNumeric(raw);
    }

    if (type === 'date') {
        return parseDate(raw);
    }

    return normalizeText(raw);
}

function compareValues(a, b, type, direction) {
    const aNull = a === null || a === undefined;
    const bNull = b === null || b === undefined;

    // Missing/unavailable values always stay at the bottom, in both directions.
    if (aNull && bNull) return 0;
    if (aNull) return 1;
    if (bNull) return -1;

    let result = 0;
    if (type === 'number' || type === 'date') {
        result = Number(a) - Number(b);
    } else {
        result = String(a).localeCompare(String(b), document.documentElement.lang || undefined, {
            numeric: true,
            sensitivity: 'base',
        });
    }

    return direction === 'desc' ? result * -1 : result;
}

function defaultDirection(type) {
    return type === 'number' || type === 'date' ? 'desc' : 'asc';
}

function tableKey(table) {
    if (table.dataset.sortKey) {
        return table.dataset.sortKey;
    }

    const headers = Array.from(table.tHead?.rows?.[0]?.cells || [])
        .map((th) => headerLabel(th))
        .join('|');
    const peers = Array.from(document.querySelectorAll('table')).filter((candidate) => {
        const candidateHeaders = Array.from(candidate.tHead?.rows?.[0]?.cells || [])
            .map((th) => headerLabel(th))
            .join('|');
        return candidateHeaders === headers;
    });
    const peerIndex = Math.max(0, peers.indexOf(table));

    return `${window.location.pathname}::${headers}::${peerIndex}`;
}

function updateHeaderState(table, activeColumn, direction) {
    const headers = Array.from(table.tHead?.rows?.[0]?.cells || []);

    headers.forEach((th, index) => {
        if (th.dataset.moxSortable !== 'true') {
            return;
        }

        const indicator = th.querySelector('[data-sort-indicator]');
        const active = index === activeColumn;
        th.setAttribute('aria-sort', active ? (direction === 'asc' ? 'ascending' : 'descending') : 'none');

        if (indicator) {
            indicator.textContent = active ? (direction === 'asc' ? '↑' : '↓') : '↕';
            indicator.style.opacity = active ? '1' : '0.42';
            indicator.style.color = active ? 'currentColor' : '';
        }
    });
}

function sortTable(table, columnIndex, direction, { remember = true } = {}) {
    const th = table.tHead?.rows?.[0]?.cells?.[columnIndex];
    if (!th) {
        return;
    }

    const type = inferColumnType(table, columnIndex, th);

    table.tBodies.forEach((tbody) => {
        const allRows = Array.from(tbody.rows);
        const sortableRows = allRows
            .filter((row) => isSortableRow(row, columnIndex))
            .map((row, originalIndex) => ({
                row,
                originalIndex,
                value: comparableValue(cellText(row, columnIndex), type),
            }));
        const fixedRows = allRows.filter((row) => !isSortableRow(row, columnIndex));

        sortableRows.sort((a, b) => {
            const compared = compareValues(a.value, b.value, type, direction);
            return compared !== 0 ? compared : a.originalIndex - b.originalIndex;
        });

        sortableRows.forEach(({ row }) => tbody.appendChild(row));
        fixedRows.forEach((row) => tbody.appendChild(row));
    });

    table.dataset.sortColumn = String(columnIndex);
    table.dataset.sortDirection = direction;
    updateHeaderState(table, columnIndex, direction);

    if (remember) {
        tableSortState.set(tableKey(table), { columnIndex, direction });
    }

    table.dispatchEvent(new CustomEvent('moxdop:table-sorted', {
        bubbles: true,
        detail: { columnIndex, direction, type },
    }));
}

function headerIsSortable(th) {
    if (!th || th.dataset.sortable === 'false' || Number(th.colSpan || 1) > 1) {
        return false;
    }

    if (th.querySelector('button, a, input, select, textarea')) {
        return false;
    }

    const label = headerLabel(th);
    return label !== '' && !ACTION_HEADER.test(label);
}

function decorateHeader(table, th, columnIndex) {
    if (th.__moxSortBound || !headerIsSortable(th)) {
        return;
    }

    th.__moxSortBound = true;
    th.dataset.moxSortable = 'true';
    th.setAttribute('aria-sort', 'none');
    th.setAttribute('tabindex', '0');
    th.setAttribute('role', 'button');
    th.style.cursor = 'pointer';
    th.style.userSelect = 'none';
    th.style.whiteSpace = th.style.whiteSpace || 'nowrap';

    const indicator = document.createElement('span');
    indicator.dataset.sortIndicator = 'true';
    indicator.setAttribute('aria-hidden', 'true');
    indicator.textContent = '↕';
    indicator.style.display = 'inline-block';
    indicator.style.marginInlineStart = '0.38rem';
    indicator.style.fontSize = '0.78em';
    indicator.style.lineHeight = '1';
    indicator.style.opacity = '0.42';
    indicator.style.verticalAlign = 'middle';
    th.appendChild(indicator);

    const activate = () => {
        const type = inferColumnType(table, columnIndex, th);
        const previousColumn = Number(table.dataset.sortColumn ?? -1);
        const previousDirection = table.dataset.sortDirection;
        const direction = previousColumn === columnIndex
            ? (previousDirection === 'asc' ? 'desc' : 'asc')
            : defaultDirection(type);
        sortTable(table, columnIndex, direction);
    };

    th.addEventListener('click', (event) => {
        if (event.target.closest('button, a, input, select, textarea')) {
            return;
        }
        activate();
    });

    th.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') {
            return;
        }
        event.preventDefault();
        activate();
    });
}

function bindTable(table) {
    if (!table || table.dataset.sortable === 'false' || !table.tHead || table.tBodies.length === 0) {
        return;
    }

    const headerRow = table.tHead.rows?.[0];
    if (!headerRow) {
        return;
    }

    Array.from(headerRow.cells).forEach((th, columnIndex) => {
        decorateHeader(table, th, columnIndex);
    });

    const remembered = tableSortState.get(tableKey(table));
    if (remembered && headerRow.cells?.[remembered.columnIndex]?.dataset.moxSortable === 'true') {
        sortTable(table, remembered.columnIndex, remembered.direction, { remember: false });
    }
}

export function bindSortableTables(root = document) {
    const tables = [];
    if (root?.matches?.('table')) {
        tables.push(root);
    }
    root?.querySelectorAll?.('table').forEach((table) => tables.push(table));
    tables.forEach(bindTable);
}

export function clearRememberedTableSorts() {
    tableSortState.clear();
}

window.MoxDopSortableTables = {
    refresh: () => bindSortableTables(document),
    clear: clearRememberedTableSorts,
};
