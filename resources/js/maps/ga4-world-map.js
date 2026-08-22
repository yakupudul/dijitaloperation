const VECTOR_CORE_URL = 'https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/jsvectormap.min.js';
const VECTOR_WORLD_URL = 'https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/maps/world.js';
const VECTOR_CSS_URL = 'https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/jsvectormap.min.css';
const CITY_DATA_URL = 'https://cdn.jsdelivr.net/gh/joelacus/world-cities@main/world_cities_15000.json';

const instances = new WeakMap();
let libraryPromise = null;
let cityIndexPromise = null;

function normalize(value) {
    return String(value || '')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/ı/g, 'i')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '');
}

function labels() {
    const tr = String(document.documentElement.lang || '').toLowerCase().startsWith('tr');
    return tr ? {
        title: 'Ziyaretçilerin dünya üzerindeki dağılımı',
        hint: 'Mavi alanlar ülke yoğunluğunu, turuncu noktalar şehir yoğunluğunu gösterir.',
        country: 'Ülke yoğunluğu',
        city: 'Şehir yoğunluğu',
        visit: 'ziyaret',
        loading: 'Harita hazırlanıyor…',
        failed: 'Harita yüklenemedi. Mevcut ülke ve şehir listeleri kullanılabilir.',
        noData: 'Seçilen dönemde konum verisi yok.',
        mapped: '{mapped}/{total} şehir haritada gösterildi.',
        allMapped: '{count} şehir haritada gösterildi.',
        countryOnly: 'Seçilen dönem için ülke yoğunluğu gösteriliyor.',
        attribution: 'Şehir koordinatları: GeoNames tabanlı world-cities (CC BY 4.0)',
    } : {
        title: 'Where visitors are located around the world',
        hint: 'Blue regions show country intensity; orange bubbles show city intensity.',
        country: 'Country intensity',
        city: 'City intensity',
        visit: 'visits',
        loading: 'Preparing map…',
        failed: 'The map could not be loaded. Existing country and city lists remain available.',
        noData: 'No location data for the selected period.',
        mapped: '{mapped}/{total} cities shown on the map.',
        allMapped: '{count} cities shown on the map.',
        countryOnly: 'Country intensity is shown for the selected period.',
        attribution: 'City coordinates: GeoNames-based world-cities (CC BY 4.0)',
    };
}

function addCss(url) {
    if (document.querySelector(`link[data-ga4-world-css="${url}"]`)) return;
    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = url;
    link.dataset.ga4WorldCss = url;
    document.head.appendChild(link);
}

function addScript(url, ready = null) {
    if (ready?.()) return Promise.resolve();

    const existing = document.querySelector(`script[data-ga4-world-script="${url}"]`);
    if (existing?.dataset.loaded === 'true') {
        return !ready || ready() ? Promise.resolve() : Promise.reject(new Error(`Expected API missing after ${url}`));
    }

    if (existing) {
        return new Promise((resolve, reject) => {
            existing.addEventListener('load', () => (!ready || ready()) ? resolve() : reject(new Error(`Expected API missing after ${url}`)), { once: true });
            existing.addEventListener('error', reject, { once: true });
        });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.dataset.ga4WorldScript = url;
        script.addEventListener('load', () => {
            script.dataset.loaded = 'true';
            (!ready || ready()) ? resolve() : reject(new Error(`Expected API missing after ${url}`));
        }, { once: true });
        script.addEventListener('error', () => reject(new Error(`Could not load ${url}`)), { once: true });
        document.head.appendChild(script);
    });
}

function constructor() {
    return window.jsVectorMap || window.JsVectorMap || null;
}

function ensureLibrary() {
    if (libraryPromise) return libraryPromise;
    libraryPromise = (async () => {
        addCss(VECTOR_CSS_URL);
        await addScript(VECTOR_CORE_URL, () => Boolean(constructor()));
        await addScript(VECTOR_WORLD_URL);
        return constructor();
    })();
    return libraryPromise;
}

function cityIndex() {
    if (cityIndexPromise) return cityIndexPromise;
    cityIndexPromise = fetch(CITY_DATA_URL, { cache: 'force-cache' })
        .then((response) => {
            if (!response.ok) throw new Error(`City dataset HTTP ${response.status}`);
            return response.json();
        })
        .then((rows) => {
            const byName = new Map();
            (Array.isArray(rows) ? rows : []).forEach((row) => {
                const key = normalize(row.name);
                const country = String(row.country || '').toUpperCase();
                const lat = Number(row.lat);
                const lng = Number(row.lng);
                if (!key || !country || !Number.isFinite(lat) || !Number.isFinite(lng)) return;
                const list = byName.get(key) || [];
                list.push({ country, lat, lng });
                byName.set(key, list);
            });
            return byName;
        });
    return cityIndexPromise;
}

function parseExistingData(analysis) {
    const countryChart = analysis.querySelector('[aria-label="Visitor countries"][data-chart]');
    if (!countryChart) return null;

    let options;
    try {
        options = JSON.parse(countryChart.getAttribute('data-chart') || '{}');
    } catch (_) {
        return null;
    }

    const names = options?.xaxis?.categories || [];
    const values = options?.series?.[0]?.data || [];
    const countries = names.map((name, index) => ({
        name: String(name || '').trim(),
        sessions: Number(values[index] || 0),
    })).filter((row) => row.name && row.sessions > 0);

    const countrySection = countryChart.closest('section');
    const grid = countrySection?.parentElement;
    const cityCard = grid?.nextElementSibling || null;
    const cityGrid = cityCard?.querySelector('.grid');
    const cities = cityGrid ? Array.from(cityGrid.children).map((row) => ({
        name: row.querySelector('span')?.textContent?.trim() || '',
        sessions: Number((row.querySelector('strong')?.textContent || '0').replace(/[^0-9-]/g, '')) || 0,
    })).filter((row) => row.name && row.sessions > 0) : [];

    return { countries, cities, countryChart, countrySection };
}

function countryCodeIndex(mapData) {
    const index = new Map();
    Object.entries(mapData?.paths || {}).forEach(([code, meta]) => {
        const key = normalize(meta?.name);
        if (key) index.set(key, String(code).toUpperCase());
    });

    const aliases = {
        turkiye: 'TR', turkey: 'TR',
        unitedstates: 'US', unitedstatesofamerica: 'US', usa: 'US',
        unitedkingdom: 'GB', greatbritain: 'GB', uk: 'GB',
        russia: 'RU', russianfederation: 'RU',
        southkorea: 'KR', northkorea: 'KP',
        czechia: 'CZ', czechrepublic: 'CZ',
        vietnam: 'VN', iran: 'IR', syria: 'SY', laos: 'LA',
        bolivia: 'BO', venezuela: 'VE', tanzania: 'TZ', moldova: 'MD', brunei: 'BN',
        northmacedonia: 'MK', macedonia: 'MK',
        ivorycoast: 'CI', cotedivoire: 'CI',
        democraticrepublicofthecongo: 'CD', drcongo: 'CD',
        republicofthecongo: 'CG',
        palestine: 'PS', palestinianterritories: 'PS',
        taiwan: 'TW', hongkong: 'HK', macau: 'MO', macao: 'MO',
    };
    Object.entries(aliases).forEach(([name, code]) => index.set(name, code));
    return index;
}

function interpolateBlue(ratio) {
    const from = [219, 234, 254];
    const to = [29, 78, 216];
    const value = Math.max(0, Math.min(1, ratio));
    const rgb = from.map((start, i) => Math.round(start + ((to[i] - start) * value)));
    return `rgb(${rgb[0]}, ${rgb[1]}, ${rgb[2]})`;
}

function applyCountryIntensity(map, rawCountries) {
    const codes = countryCodeIndex(map.mapData);
    const resolved = [];

    rawCountries.forEach((country) => {
        const code = codes.get(normalize(country.name));
        if (!code || !map.regions?.[code]) return;
        resolved.push({ ...country, code });
    });

    const values = resolved.map((row) => Number(row.sessions || 0));
    const min = Math.min(...values, 0);
    const max = Math.max(...values, 1);
    const total = Math.max(1, values.reduce((sum, value) => sum + value, 0));
    const lookup = new Map();

    resolved.forEach((country) => {
        const sessions = Number(country.sessions || 0);
        const ratio = max === min ? 1 : (sessions - min) / (max - min);
        map.regions[country.code].element.setStyle('fill', interpolateBlue(ratio));
        lookup.set(country.code, {
            ...country,
            share: Math.round((sessions / total) * 1000) / 10,
        });
    });

    return { lookup, allowedCodes: new Set(resolved.map((row) => row.code)) };
}

function resolveCity(city, index, allowedCodes) {
    const candidates = (index.get(normalize(city.name)) || []).filter((candidate) => !allowedCodes.size || allowedCodes.has(candidate.country));
    return candidates.length === 1 ? candidates[0] : null;
}

function markerColor(ratio) {
    if (ratio >= 0.75) return '#c2410c';
    if (ratio >= 0.5) return '#ea580c';
    if (ratio >= 0.25) return '#f97316';
    if (ratio >= 0.1) return '#fb923c';
    return '#fdba74';
}

function buildMarkers(cities, index, allowedCodes) {
    const max = Math.max(1, ...cities.map((city) => Number(city.sessions || 0)));
    return cities.map((city) => {
        const location = resolveCity(city, index, allowedCodes);
        if (!location) return null;
        const sessions = Number(city.sessions || 0);
        const ratio = Math.max(0, Math.min(1, sessions / max));
        return {
            name: city.name,
            coords: [location.lat, location.lng],
            sessions,
            style: {
                initial: {
                    fill: markerColor(ratio),
                    stroke: '#ffffff',
                    'stroke-width': 1.5,
                    r: Math.round((4 + Math.sqrt(ratio) * 8) * 10) / 10,
                },
                hover: { fill: '#9a3412', stroke: '#ffffff', 'stroke-width': 2 },
            },
        };
    }).filter(Boolean);
}

function setStatus(section, text) {
    const target = section.querySelector('[data-ga4-world-status]');
    if (target) target.textContent = text;
}

function createChrome(data, text) {
    const section = data.countrySection;
    if (!section || section.dataset.ga4WorldEnhanced === 'true') return null;
    section.dataset.ga4WorldEnhanced = 'true';

    const heading = section.querySelector('h4');
    if (heading) heading.textContent = text.title;

    const hint = document.createElement('p');
    hint.className = 'mt-1 text-xs text-gray-400';
    hint.textContent = text.hint;
    heading?.insertAdjacentElement('afterend', hint);

    const legend = document.createElement('div');
    legend.className = 'mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-500 dark:text-gray-400';
    legend.innerHTML = `
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-blue-600"></span>${text.country}</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-orange-500 ring-2 ring-orange-100 dark:ring-orange-900/30"></span>${text.city}</span>
    `;
    hint.insertAdjacentElement('afterend', legend);

    const map = document.createElement('div');
    map.className = 'mt-3 min-h-[360px] w-full overflow-hidden rounded-xl bg-gray-50/60 dark:bg-white/[0.02]';
    map.style.height = '390px';
    legend.insertAdjacentElement('afterend', map);

    const footer = document.createElement('div');
    footer.className = 'mt-2 flex flex-wrap items-center justify-between gap-2 text-[11px] text-gray-400';
    footer.innerHTML = `<span data-ga4-world-status>${text.loading}</span><span>${text.attribution}</span>`;
    map.insertAdjacentElement('afterend', footer);

    return map;
}

async function mount(data) {
    const text = labels();
    const host = createChrome(data, text);
    if (!host) return;

    if (!data.countries.length && !data.cities.length) {
        setStatus(data.countrySection, text.noData);
        return;
    }

    let Constructor;
    let cities;
    try {
        const results = await Promise.allSettled([ensureLibrary(), cityIndex()]);
        if (results[0].status !== 'fulfilled') throw results[0].reason;
        Constructor = results[0].value;
        cities = results[1].status === 'fulfilled' ? results[1].value : new Map();
    } catch (_) {
        setStatus(data.countrySection, text.failed);
        return;
    }

    const countryTooltip = new Map();
    let markers = [];
    let map;

    try {
        map = new Constructor({
            selector: host,
            map: 'world',
            backgroundColor: 'transparent',
            zoomButtons: false,
            zoomOnScroll: false,
            draggable: true,
            regionStyle: {
                initial: {
                    fill: document.documentElement.classList.contains('dark') ? '#344054' : '#e4e7ec',
                    stroke: document.documentElement.classList.contains('dark') ? '#101828' : '#ffffff',
                    'stroke-width': 0.7,
                },
                hover: { fill: '#2563eb', 'fill-opacity': 0.94, cursor: 'pointer' },
            },
            markerStyle: {
                initial: { fill: '#f97316', stroke: '#ffffff', 'stroke-width': 1.5, r: 5 },
                hover: { fill: '#c2410c', stroke: '#ffffff', 'stroke-width': 2 },
            },
            onRegionTooltipShow(event, tooltip, code) {
                const country = countryTooltip.get(String(code || '').toUpperCase());
                if (!country) return;
                tooltip.text(`${country.name} · ${Number(country.sessions).toLocaleString()} ${text.visit} · ${Number(country.share).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`);
            },
            onMarkerTooltipShow(event, tooltip, index) {
                const marker = markers[Number(index)];
                if (!marker) return;
                tooltip.text(`${marker.name} · ${Number(marker.sessions).toLocaleString()} ${text.visit}`);
            },
        });
    } catch (_) {
        setStatus(data.countrySection, text.failed);
        return;
    }

    const countryResult = applyCountryIntensity(map, data.countries);
    countryResult.lookup.forEach((value, key) => countryTooltip.set(key, value));
    markers = buildMarkers(data.cities, cities, countryResult.allowedCodes);
    if (markers.length) map.addMarkers(markers);

    instances.set(host, map);
    data.countryChart.classList.add('hidden');

    if (data.cities.length && markers.length < data.cities.length) {
        setStatus(data.countrySection, text.mapped.replace('{mapped}', String(markers.length)).replace('{total}', String(data.cities.length)));
    } else if (data.cities.length) {
        setStatus(data.countrySection, text.allMapped.replace('{count}', String(markers.length)));
    } else {
        setStatus(data.countrySection, text.countryOnly);
    }
}

function enhance(root = document) {
    const scope = root?.querySelectorAll ? root : document;
    const analyses = [];
    if (scope.matches?.('[data-website-ga4-analysis]')) analyses.push(scope);
    scope.querySelectorAll?.('[data-website-ga4-analysis]').forEach((analysis) => analyses.push(analysis));

    analyses.forEach((analysis) => {
        if (analysis.querySelector('[data-ga4-world-status]')) return;
        const data = parseExistingData(analysis);
        if (data) mount(data);
    });
}

document.addEventListener('DOMContentLoaded', () => enhance());
document.addEventListener('livewire:navigated', () => enhance());
document.addEventListener('livewire:init', () => {
    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.updated', ({ el }) => enhance(el));
    }
});

window.MoxDopGa4WorldMap = { refresh: () => enhance() };
