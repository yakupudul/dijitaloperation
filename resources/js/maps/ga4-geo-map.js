const VECTOR_CORE_URL = 'https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/jsvectormap.min.js';
const VECTOR_WORLD_URL = 'https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/maps/world.js';
const VECTOR_CSS_URL = 'https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/jsvectormap.min.css';
const CITY_DATA_URL = 'https://cdn.jsdelivr.net/gh/joelacus/world-cities@main/world_cities_15000.json';

const instances = new WeakMap();
let vectorLibraryPromise = null;
let cityIndexPromise = null;

function normalizeName(value) {
    return String(value || '')
        .normalize('NFKD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/ı/g, 'i')
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '');
}

function uiLabels() {
    const tr = String(document.documentElement.lang || '').toLowerCase().startsWith('tr');

    return tr
        ? {
            title: 'Ziyaretçilerin dünya üzerindeki dağılımı',
            hint: 'Mavi alanlar ülke yoğunluğunu, turuncu noktalar şehir yoğunluğunu gösterir.',
            countryIntensity: 'Ülke yoğunluğu',
            cityIntensity: 'Şehir yoğunluğu',
            sessions: 'ziyaret',
            loading: 'Harita hazırlanıyor…',
            failed: 'Harita yüklenemedi. Ülke ve şehir listeleri kullanılabilir.',
            noData: 'Seçilen dönemde konum verisi yok.',
            partialCities: '{mapped}/{total} şehir haritada gösterildi.',
            allCities: '{count} şehir haritada gösterildi.',
            countryOnly: 'Seçilen dönem için ülke yoğunluğu gösteriliyor.',
            attribution: 'Şehir koordinatları: GeoNames tabanlı world-cities (CC BY 4.0)',
        }
        : {
            title: 'Where visitors are located around the world',
            hint: 'Blue regions show country intensity; orange bubbles show city intensity.',
            countryIntensity: 'Country intensity',
            cityIntensity: 'City intensity',
            sessions: 'visits',
            loading: 'Preparing map…',
            failed: 'The map could not be loaded. Country and city lists remain available.',
            noData: 'No location data for the selected period.',
            partialCities: '{mapped}/{total} cities shown on the map.',
            allCities: '{count} cities shown on the map.',
            countryOnly: 'Country intensity is shown for the selected period.',
            attribution: 'City coordinates: GeoNames-based world-cities (CC BY 4.0)',
        };
}

function loadStylesheet(href) {
    if (document.querySelector(`link[data-ga4-geo-css="${href}"]`)) return;

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.ga4GeoCss = href;
    document.head.appendChild(link);
}

function loadScript(src, ready) {
    if (ready()) return Promise.resolve();

    const existing = document.querySelector(`script[data-ga4-geo-script="${src}"]`);
    if (existing) {
        if (existing.dataset.loaded === 'true') {
            return ready()
                ? Promise.resolve()
                : Promise.reject(new Error(`Loaded script did not expose expected API: ${src}`));
        }

        return new Promise((resolve, reject) => {
            existing.addEventListener('load', () => {
                existing.dataset.loaded = 'true';
                ready() ? resolve() : reject(new Error(`Loaded script did not expose expected API: ${src}`));
            }, { once: true });
            existing.addEventListener('error', reject, { once: true });
        });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.dataset.ga4GeoScript = src;
        script.addEventListener('load', () => {
            script.dataset.loaded = 'true';
            ready() ? resolve() : reject(new Error(`Loaded script did not expose expected API: ${src}`));
        }, { once: true });
        script.addEventListener('error', () => reject(new Error(`Could not load ${src}`)), { once: true });
        document.head.appendChild(script);
    });
}

function vectorConstructor() {
    return window.jsVectorMap || window.JsVectorMap || null;
}

function worldDefinition(Constructor = vectorConstructor()) {
    return Constructor?.maps?.world || window.jsVectorMap?.maps?.world || window.JsVectorMap?.maps?.world || null;
}

function ensureVectorLibrary() {
    if (vectorLibraryPromise) return vectorLibraryPromise;

    vectorLibraryPromise = (async () => {
        loadStylesheet(VECTOR_CSS_URL);
        await loadScript(VECTOR_CORE_URL, () => Boolean(vectorConstructor()));
        await loadScript(VECTOR_WORLD_URL, () => Boolean(worldDefinition()));

        const Constructor = vectorConstructor();
        if (! Constructor || ! worldDefinition(Constructor)) {
            throw new Error('jsVectorMap world map is unavailable.');
        }

        return Constructor;
    })();

    return vectorLibraryPromise;
}

function buildCityIndex() {
    if (cityIndexPromise) return cityIndexPromise;

    cityIndexPromise = fetch(CITY_DATA_URL, { cache: 'force-cache' })
        .then((response) => {
            if (! response.ok) throw new Error(`City coordinate dataset returned ${response.status}`);
            return response.json();
        })
        .then((rows) => {
            const byCountryAndName = new Map();
            const byName = new Map();

            (Array.isArray(rows) ? rows : []).forEach((row) => {
                const country = String(row.country || '').toUpperCase();
                const name = normalizeName(row.name);
                const lat = Number(row.lat);
                const lng = Number(row.lng);
                if (! country || ! name || ! Number.isFinite(lat) || ! Number.isFinite(lng)) return;

                const item = { country, name: row.name, lat, lng };
                const exactKey = `${country}|${name}`;
                if (! byCountryAndName.has(exactKey)) byCountryAndName.set(exactKey, []);
                byCountryAndName.get(exactKey).push(item);

                if (! byName.has(name)) byName.set(name, []);
                byName.get(name).push(item);
            });

            return { byCountryAndName, byName };
        });

    return cityIndexPromise;
}

function countryNameIndex(Constructor) {
    const index = new Map();
    const paths = worldDefinition(Constructor)?.paths || {};

    Object.entries(paths).forEach(([code, meta]) => {
        const name = normalizeName(meta?.name);
        if (name) index.set(name, String(code).toUpperCase());
    });

    const aliases = {
        turkiye: 'TR', turkey: 'TR',
        unitedstates: 'US', unitedstatesofamerica: 'US', usa: 'US', us: 'US',
        unitedkingdom: 'GB', greatbritain: 'GB', uk: 'GB',
        russia: 'RU', russianfederation: 'RU',
        southkorea: 'KR', korearepublicof: 'KR',
        northkorea: 'KP', koreademocraticpeoplesrepublicof: 'KP',
        czechia: 'CZ', czechrepublic: 'CZ',
        vietnam: 'VN', vietnam: 'VN',
        iran: 'IR', iranislamicrepublicof: 'IR',
        syria: 'SY', syrianarabrepublic: 'SY',
        laos: 'LA', laopeoplesdemocraticrepublic: 'LA',
        bolivia: 'BO', boliviaplurinationalstateof: 'BO',
        venezuela: 'VE', venezuelabolivarianrepublicof: 'VE',
        tanzania: 'TZ', tanzaniaunitedrepublicof: 'TZ',
        moldova: 'MD', moldovarepublicof: 'MD',
        brunei: 'BN', bruneidarussalam: 'BN',
        northmacedonia: 'MK', macedonia: 'MK',
        ivorycoast: 'CI', cotedivoire: 'CI',
        democraticrepublicofthecongo: 'CD', drcongo: 'CD', congokinshasa: 'CD',
        republicofthecongo: 'CG', congobrazzaville: 'CG',
        palestine: 'PS', palestinianterritories: 'PS', palestinestateof: 'PS',
        taiwan: 'TW', taiwanprovinceofchina: 'TW',
        hongkong: 'HK', macau: 'MO', macao: 'MO',
    };

    Object.entries(aliases).forEach(([name, code]) => index.set(name, code));

    return index;
}

function resolveCountries(rawCountries, Constructor) {
    const nameIndex = countryNameIndex(Constructor);
    const total = Math.max(1, rawCountries.reduce((sum, row) => sum + Number(row.sessions || 0), 0));

    return rawCountries.map((country) => ({
        ...country,
        code: country.code || nameIndex.get(normalizeName(country.name)) || null,
        share: country.share ?? Math.round((Number(country.sessions || 0) / total) * 1000) / 10,
    }));
}

function resolveCityCoordinate(city, index, allowedCountryCodes) {
    const country = String(city.country_code || '').toUpperCase();
    const name = normalizeName(city.name);
    if (! name) return null;

    if (country) {
        const exact = index.byCountryAndName.get(`${country}|${name}`) || [];
        return exact.length === 1 ? exact[0] : null;
    }

    let candidates = index.byName.get(name) || [];
    if (allowedCountryCodes.size) {
        candidates = candidates.filter((candidate) => allowedCountryCodes.has(candidate.country));
    }

    return candidates.length === 1 ? candidates[0] : null;
}

function markerColor(ratio) {
    if (ratio >= 0.75) return '#c2410c';
    if (ratio >= 0.5) return '#ea580c';
    if (ratio >= 0.25) return '#f97316';
    if (ratio >= 0.1) return '#fb923c';
    return '#fdba74';
}

function cityMarkers(cities, index, allowedCountryCodes) {
    const maxSessions = Math.max(1, ...cities.map((city) => Number(city.sessions || 0)));
    const resolved = [];

    cities.forEach((city) => {
        const coordinate = resolveCityCoordinate(city, index, allowedCountryCodes);
        if (! coordinate) return;

        const sessions = Number(city.sessions || 0);
        const ratio = Math.max(0, Math.min(1, sessions / maxSessions));
        const radius = Math.round((4 + Math.sqrt(ratio) * 8) * 10) / 10;
        const detail = [city.region, city.country].filter(Boolean).join(', ');

        resolved.push({
            name: city.name,
            coords: [coordinate.lat, coordinate.lng],
            meta: { city, sessions, detail },
            style: {
                initial: {
                    fill: markerColor(ratio),
                    stroke: '#ffffff',
                    'stroke-width': 1.5,
                    r: radius,
                },
                hover: {
                    fill: '#9a3412',
                    stroke: '#ffffff',
                    'stroke-width': 2,
                    cursor: 'pointer',
                },
            },
        });
    });

    return resolved;
}

function status(el, text, tone = 'muted') {
    const host = el.closest('[data-ga4-geo-card]');
    const statusEl = host?.querySelector('[data-ga4-geo-status]');
    if (! statusEl) return;
    statusEl.textContent = text || '';
    statusEl.dataset.tone = tone;
}

function parseLegacyGeo(analysis) {
    const countryChart = analysis.querySelector('[aria-label="Visitor countries"][data-chart]');
    if (! countryChart) return null;

    let chart;
    try {
        chart = JSON.parse(countryChart.getAttribute('data-chart') || '{}');
    } catch (_) {
        return null;
    }

    const names = chart?.xaxis?.categories || [];
    const values = chart?.series?.[0]?.data || [];
    const countries = names.map((name, index) => ({
        name: String(name || ''),
        sessions: Number(values[index] || 0),
    })).filter((row) => row.name && row.sessions > 0);

    const countrySection = countryChart.closest('section');
    const grid = countrySection?.parentElement;
    const cityCard = grid?.nextElementSibling || null;
    const cityGrid = cityCard?.querySelector('.grid');
    const cities = cityGrid
        ? Array.from(cityGrid.children).map((row) => ({
            name: row.querySelector('span')?.textContent?.trim() || '',
            sessions: Number((row.querySelector('strong')?.textContent || '0').replace(/[^0-9.-]/g, '')) || 0,
        })).filter((row) => row.name && row.sessions > 0)
        : [];

    return { countries, cities, countryChart, countrySection, cityCard };
}

function buildMapChrome(legacy, payload, labels) {
    const section = legacy.countrySection;
    if (! section || section.dataset.ga4GeoEnhanced === 'true') return null;

    section.dataset.ga4GeoEnhanced = 'true';
    section.dataset.ga4GeoCard = 'true';

    const heading = section.querySelector('h4');
    if (heading) heading.textContent = labels.title;

    const hint = document.createElement('p');
    hint.className = 'mt-1 text-xs text-gray-400';
    hint.textContent = labels.hint;
    heading?.insertAdjacentElement('afterend', hint);

    const legend = document.createElement('div');
    legend.className = 'mt-3 flex flex-wrap items-center gap-x-4 gap-y-2 text-xs text-gray-500 dark:text-gray-400';
    legend.innerHTML = `
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-sm bg-blue-600"></span>${labels.countryIntensity}</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full bg-orange-500 ring-2 ring-orange-100 dark:ring-orange-900/30"></span>${labels.cityIntensity}</span>
    `;
    hint.insertAdjacentElement('afterend', legend);

    const map = document.createElement('div');
    map.className = 'mt-3 min-h-[360px] w-full overflow-hidden rounded-xl bg-gray-50/60 dark:bg-white/[0.02]';
    map.style.height = '390px';
    map.setAttribute('data-ga4-geo-map', JSON.stringify(payload));
    map.__ga4FallbackEl = legacy.countryChart;
    legend.insertAdjacentElement('afterend', map);

    const footer = document.createElement('div');
    footer.className = 'mt-2 flex flex-wrap items-center justify-between gap-2 text-[11px] text-gray-400';
    footer.innerHTML = `<span data-ga4-geo-status>${labels.loading}</span><span>${labels.attribution}</span>`;
    map.insertAdjacentElement('afterend', footer);

    return map;
}

async function mountGeoMap(el) {
    const payloadRaw = el.getAttribute('data-ga4-geo-map') || '{}';
    if (el.__ga4GeoPayload === payloadRaw && instances.has(el)) return;

    if (instances.has(el)) {
        try { instances.get(el)?.destroy?.(); } catch (_) {}
        instances.delete(el);
    }

    let payload;
    try {
        payload = JSON.parse(payloadRaw);
    } catch (_) {
        status(el, 'Map data could not be read.', 'error');
        return;
    }

    const rawCountries = Array.isArray(payload.countries) ? payload.countries : [];
    const cities = Array.isArray(payload.cities) ? payload.cities : [];
    const labels = payload.labels || uiLabels();

    if (! rawCountries.length && ! cities.length) {
        status(el, labels.noData);
        return;
    }

    status(el, labels.loading);

    let Constructor;
    let cityIndex = null;
    try {
        const results = await Promise.allSettled([ensureVectorLibrary(), buildCityIndex()]);
        if (results[0].status !== 'fulfilled') throw results[0].reason;
        Constructor = results[0].value;
        cityIndex = results[1].status === 'fulfilled' ? results[1].value : null;
    } catch (_) {
        status(el, labels.failed, 'error');
        return;
    }

    const countries = resolveCountries(rawCountries, Constructor);
    const allowedCountryCodes = new Set(countries.map((row) => row.code).filter(Boolean));
    const markers = cityIndex ? cityMarkers(cities, cityIndex, allowedCountryCodes) : [];
    const values = {};
    const countriesByCode = new Map();

    countries.forEach((country) => {
        const code = String(country.code || '').toUpperCase();
        const sessions = Number(country.sessions || 0);
        if (! code || sessions <= 0) return;
        values[code] = sessions;
        countriesByCode.set(code, country);
    });

    const dark = document.documentElement.classList.contains('dark');
    el.innerHTML = '';

    let map;
    try {
        map = new Constructor({
            selector: el,
            map: 'world',
            backgroundColor: 'transparent',
            zoomButtons: false,
            zoomOnScroll: false,
            draggable: true,
            regionStyle: {
                initial: {
                    fill: dark ? '#344054' : '#e4e7ec',
                    'fill-opacity': 1,
                    stroke: dark ? '#101828' : '#ffffff',
                    'stroke-width': 0.7,
                    'stroke-opacity': 1,
                },
                hover: { fill: '#2563eb', 'fill-opacity': 0.94, cursor: 'pointer' },
            },
            series: {
                regions: [{
                    attribute: 'fill',
                    values,
                    scale: ['#dbeafe', '#1d4ed8'],
                    normalizeFunction: 'polynomial',
                }],
            },
            markers,
            markerStyle: {
                initial: { fill: '#f97316', stroke: '#ffffff', 'stroke-width': 1.5, r: 5 },
                hover: { fill: '#c2410c', stroke: '#ffffff', 'stroke-width': 2 },
            },
            onRegionTooltipShow(event, tooltip, code) {
                const country = countriesByCode.get(String(code || '').toUpperCase());
                if (! country) return;
                tooltip.text(`${country.name} · ${Number(country.sessions || 0).toLocaleString()} ${labels.sessions} · ${Number(country.share || 0).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`);
            },
            onMarkerTooltipShow(event, tooltip, index) {
                const marker = markers[Number(index)];
                if (! marker?.meta) return;
                const suffix = marker.meta.detail ? ` · ${marker.meta.detail}` : '';
                tooltip.text(`${marker.name}${suffix} · ${Number(marker.meta.sessions || 0).toLocaleString()} ${labels.sessions}`);
            },
        });
    } catch (_) {
        status(el, labels.failed, 'error');
        return;
    }

    instances.set(el, map);
    el.__ga4GeoPayload = payloadRaw;
    el.__ga4FallbackEl?.classList.add('hidden');

    const mapped = markers.length;
    if (cities.length && mapped < cities.length) {
        status(el, labels.partialCities.replace('{mapped}', String(mapped)).replace('{total}', String(cities.length)));
    } else if (cities.length) {
        status(el, labels.allCities.replace('{count}', String(mapped)));
    } else {
        status(el, labels.countryOnly);
    }
}

function enhanceExistingGa4Geo(root = document) {
    const scope = root?.querySelectorAll ? root : document;
    const analyses = [];

    if (scope.matches?.('[data-website-ga4-analysis]')) analyses.push(scope);
    scope.querySelectorAll?.('[data-website-ga4-analysis]').forEach((analysis) => analyses.push(analysis));

    analyses.forEach((analysis) => {
        if (analysis.querySelector('[data-ga4-geo-map]')) return;
        const legacy = parseLegacyGeo(analysis);
        if (! legacy || (! legacy.countries.length && ! legacy.cities.length)) return;

        const labels = uiLabels();
        const map = buildMapChrome(legacy, {
            countries: legacy.countries,
            cities: legacy.cities,
            labels,
        }, labels);
        if (map) mountGeoMap(map);
    });
}

function renderGa4GeoMaps(root = document) {
    enhanceExistingGa4Geo(root);
    const scope = root?.querySelectorAll ? root : document;
    if (scope.matches?.('[data-ga4-geo-map]')) mountGeoMap(scope);
    scope.querySelectorAll?.('[data-ga4-geo-map]').forEach((el) => mountGeoMap(el));
}

document.addEventListener('DOMContentLoaded', () => renderGa4GeoMaps());
document.addEventListener('livewire:navigated', () => renderGa4GeoMaps());
document.addEventListener('livewire:init', () => {
    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.updated', ({ el }) => renderGa4GeoMaps(el));
    }
});

window.MoxDopGa4GeoMap = { refresh: () => renderGa4GeoMaps() };
