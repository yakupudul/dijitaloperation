/* MoxDOP hızlı not: one box, Enter saves, the list below ticks. config.js (from the panel) holds MOXDOP.base and MOXDOP.token. */
const $ = (id) => document.getElementById(id);
const api = (path, options = {}) => fetch(MOXDOP.base + '/api/hizli-not' + path, {
    ...options,
    headers: { 'Authorization': 'Bearer ' + MOXDOP.token, 'Accept': 'application/json', 'Content-Type': 'application/json' },
});
let page = null;
let takvim = MOXDOP.base + '/takvim';

function say(text, error = false) {
    const el = $('said');
    el.textContent = text;
    el.hidden = false;
    el.className = 'said' + (error ? ' err' : '');
}

function item(row) {
    const li = document.createElement('li');
    if (row.done) li.className = 'done';
    const box = document.createElement('input');
    box.type = 'checkbox';
    box.checked = !!row.done;
    box.title = 'Yapıldı';
    box.addEventListener('change', async () => {
        li.classList.toggle('done', box.checked);
        const res = await api('/' + row.id + '/tik', { method: 'POST', body: '{}' }).catch(() => null);
        if (!res || !res.ok) { say('Kaydedilemedi; internet ya da anahtar sorunu.', true); box.checked = !box.checked; li.classList.toggle('done', box.checked); }
    });
    const t = document.createElement('span');
    t.className = 't';
    t.textContent = (row.time ? row.time + ' · ' : '') + (row.kind === 'meeting' ? 'Toplantı: ' : '') + row.title;
    const meta = [row.late, row.brand].filter(Boolean).join(' · ');
    if (meta) { const m = document.createElement('span'); m.className = 'm'; m.textContent = meta; t.appendChild(m); }
    li.append(box, t);
    return li;
}

async function load() {
    const list = $('list');
    let data;
    try {
        const res = await api('');
        if (res.status === 401) { list.innerHTML = '<p class="empty">Anahtar geçersiz: eklentiyi Takvim › "+ eklentisi"nden yeniden indir.</p>'; return; }
        data = await res.json();
    } catch (e) {
        list.innerHTML = '<p class="empty">MoxDOP\'a ulaşılamadı.</p>';
        return;
    }
    takvim = data.takvim || takvim;
    list.innerHTML = '';
    const groups = [['Bugün', data.today, ''], ['Geciken', data.late, 'late'], ['Tarihsiz', data.open, '']];
    let any = false;
    for (const [label, rows, cls] of groups) {
        if (!rows || !rows.length) continue;
        any = true;
        const h = document.createElement('h3');
        h.textContent = label;
        if (cls) h.className = cls;
        const ul = document.createElement('ul');
        rows.slice(0, 12).forEach((r) => ul.appendChild(item(r)));
        list.append(h, ul);
    }
    if (!any) list.innerHTML = '<p class="empty">Açık iş yok.</p>';
}

async function save(event) {
    event?.preventDefault();
    const text = $('text').value.trim();
    if (!text) return;
    $('save').disabled = true;
    try {
        const res = await api('', { method: 'POST', body: JSON.stringify({ text, url: $('withPage').checked && page ? page.url : null }) });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) { say(data.message || 'Kaydedilemedi.', true); return; }
        $('text').value = '';
        try { localStorage.removeItem('draft'); } catch (e) {}
        say('✓ ' + data.message);
        load();
    } catch (e) {
        say('MoxDOP\'a ulaşılamadı; yazdığın kutuda duruyor.', true);
    } finally {
        $('save').disabled = false;
        $('text').focus();
    }
}

$('form').addEventListener('submit', save);
$('text').addEventListener('keydown', (e) => { if (e.key === 'Enter' && !e.shiftKey) save(e); });
$('open').addEventListener('click', (e) => { e.preventDefault(); chrome.tabs.create({ url: takvim }); });

chrome.tabs.query({ active: true, currentWindow: true }, (tabs) => {
    const tab = tabs && tabs[0];
    if (tab && /^https?:/.test(tab.url || '') && !(tab.url || '').startsWith(MOXDOP.base)) {
        page = { url: tab.url, title: tab.title };
        $('pageLabel').textContent = 'Sayfayı ekle: ' + (tab.title || new URL(tab.url).hostname);
    } else {
        $('withPage').closest('label').hidden = true;
    }
});
// A popup closes when it loses focus: what was typed waits for the next open.
try { $('text').value = localStorage.getItem('draft') || ''; } catch (e) {}
$('text').addEventListener('input', () => { try { localStorage.setItem('draft', $('text').value); } catch (e) {} });
$('text').focus();
load();
