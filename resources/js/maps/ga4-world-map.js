const VECTOR_CORE_URL = 'https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/jsvectormap.min.js';
const VECTOR_WORLD_URL = 'https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/maps/world.js';
const VECTOR_CSS_URL = 'https://cdn.jsdelivr.net/npm/jsvectormap@1.7.0/dist/jsvectormap.min.css';

let libraryPromise = null;

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
        title: 'Ziyaretçilerin ülkeleri',
        hint: 'Seçilen dönemde web sitesi ziyaretlerinin ülkelere göre dağılımı. Mavi koyulaştıkça ziyaret yoğunluğu artar.',
        visit: 'ziyaret',
        share: 'gösterilen ülke trafiğindeki pay',
        loading: 'Harita hazırlanıyor…',
        ready: 'Ülke yoğunluğu seçilen tarih aralığına göre gösteriliyor.',
        failed: 'Harita yüklenemedi. Ülke listesi kullanılabilir.',
        noData: 'Seçilen dönemde ülke verisi yok.',
    } : {
        title: 'Visitor countries',
        hint: 'Distribution of website visits by country for the selected period. Darker blue means higher visit intensity.',
        visit: 'visits',
        share: 'share of displayed country traffic',
        loading: 'Preparing map…',
        ready: 'Country intensity reflects the selected date range.',
        failed: 'The map could not be loaded. The country list remains available.',
        noData: 'No country data for the selected period.',
    };
}

function addCss(url) {
    if (document.querySelector(`link[data-ga4-tailadmin-map-css="${url}"]`)) return;

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = url;
    link.dataset.ga4TailadminMapCss = url;
    document.head.appendChild(link);
}

function addScript(url, ready = null) {
    if (ready?.()) return Promise.resolve();

    const existing = document.querySelector(`script[data-ga4-tailadmin-map-script="${url}"]`);
    if (existing?.dataset.loaded === 'true') {
        return !ready || ready()
            ? Promise.resolve()
            : Promise.reject(new Error(`Expected API missing after ${url}`));
    }

    if (existing) {
        return new Promise((resolve, reject) => {
            existing.addEventListener('load', () => {
                (!ready || ready())
                    ? resolve()
                    : reject(new Error(`Expected API missing after ${url}`));
            }, { once: true });
            existing.addEventListener('error', reject, { once: true });
        });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.dataset.ga4TailadminMapScript = url;
        script.addEventListener('load', () => {
            script.dataset.loaded = 'true';
            (!ready || ready())
                ? resolve()
                : reject(new Error(`Expected API missing after ${url}`));
        }, { once: true });
        script.addEventListener('error', () => reject(new Error(`Could not load ${url}`)), { once: true });
        document.head.appendChild(script);
    });
}

function vectorConstructor() {
    return window.jsVectorMap || window.JsVectorMap || null;
}

function ensureLibrary() {
    if (libraryPromise) return libraryPromise;

    libraryPromise = (async () => {
        addCss(VECTOR_CSS_URL);
        await addScript(VECTOR_CORE_URL, () => Boolean(vectorConstructor()));
        await addScript(VECTOR_WORLD_URL);

        const Constructor = vectorConstructor();
        if (!Constructor) throw new Error('jsVectorMap is unavailable.');

        return Constructor;
    })();

    return libraryPromise;
}

function hideCitySection(analysis, countrySection = null) {
    const section = countrySection || analysis.querySelector('[aria-label="Visitor countries"]')?.closest('section');
    const audienceGrid = section?.parentElement || null;
    const possibleCityCard = audienceGrid?.nextElementSibling || null;

    if (possibleCityCard?.querySelector('.grid strong')) {
        possibleCityCard.classList.add('hidden');
        possibleCityCard.setAttribute('aria-hidden', 'true');
    }
}

function purgeLegacyCityMap(section) {
    // The first GA4 map prototype injected a country+city legend and orange city
    // markers directly into this section. Remove that generated chrome if a
    // Livewire navigation preserved it while a newer asset bundle was loaded.
    const legacyStatus = section?.querySelector('[data-ga4-world-status]');
    if (!legacyStatus) {
        delete section?.dataset.ga4WorldEnhanced;
        return;
    }

    const footer = legacyStatus.closest('div');
    const mapHost = footer?.previousElementSibling || null;
    const legend = mapHost?.previousElementSibling || null;
    const hint = legend?.previousElementSibling || null;

    [footer, mapHost, legend, hint].forEach((node) => {
        if (node && !node.matches('[data-chart]')) {
            node.remove();
        }
    });

    delete section.dataset.ga4WorldEnhanced;
}

function parseCountryData(analysis) {
    const countryChart = analysis.querySelector('[aria-label="Visitor countries"][data-chart]');
    if (!countryChart) return null;

    let options;
    try {
        options = JSON.parse(countryChart.getAttribute('data-chart') || '{}');
    } catch (_) {
        return null;
    }

    const names = Array.isArray(options?.xaxis?.categories) ? options.xaxis.categories : [];
    const values = Array.isArray(options?.series?.[0]?.data) ? options.series[0].data : [];
    const countries = names.map((name, index) => ({
        name: String(name || '').trim(),
        sessions: Number(values[index] || 0),
    })).filter((row) => row.name && row.sessions > 0);

    const countrySection = countryChart.closest('section');
    if (!countrySection) return null;

    return {
        countries,
        countryChart,
        countrySection,
        signature: JSON.stringify(countries),
    };
}

function countryCodeIndex(mapData) {
    const index = new Map();

    Object.entries(mapData?.paths || {}).forEach(([code, meta]) => {
        const key = normalize(meta?.name);
        if (key) index.set(key, String(code).toUpperCase());
    });

    const aliases = {
        turkiye: 'TR',
        turkey: 'TR',
        unitedstates: 'US',
        unitedstatesofamerica: 'US',
        usa: 'US',
        unitedkingdom: 'GB',
        greatbritain: 'GB',
        uk: 'GB',
        russia: 'RU',
        russianfederation: 'RU',
        southkorea: 'KR',
        northkorea: 'KP',
        czechia: 'CZ',
        czechrepublic: 'CZ',
        vietnam: 'VN',
        iran: 'IR',
        syria: 'SY',
        laos: 'LA',
        bolivia: 'BO',
        venezuela: 'VE',
        tanzania: 'TZ',
        moldova: 'MD',
        brunei: 'BN',
        northmacedonia: 'MK',
        macedonia: 'MK',
        ivorycoast: 'CI',
        cotedivoire: 'CI',
        democraticrepublicofthecongo: 'CD',
        drcongo: 'CD',
        republicofthecongo: 'CG',
        palestine: 'PS',
        palestinianterritories: 'PS',
        taiwan: 'TW',
        hongkong: 'HK',
        macau: 'MO',
        macao: 'MO',
    };

    Object.entries(aliases).forEach(([name, code]) => index.set(name, code));

    return index;
}

function tailAdminBlue(ratio) {
    const from = [221, 227, 255];
    const to = [70, 95, 255];
    const value = Math.max(0, Math.min(1, ratio));
    const rgb = from.map((start, index) => Math.round(start + ((to[index] - start) * value)));

    return `rgb(${rgb[0]}, ${rgb[1]}, ${rgb[2]})`;
}

function shadeCountries(map, countries) {
    const codes = countryCodeIndex(map.mapData);
    const maxSessions = Math.max(1, ...countries.map((country) => Number(country.sessions || 0)));
    const totalSessions = Math.max(1, countries.reduce((sum, country) => sum + Number(country.sessions || 0), 0));
    const lookup = new Map();

    countries.forEach((country) => {
        const code = codes.get(normalize(country.name));
        if (!code || !map.regions?.[code]) return;

        const sessions = Number(country.sessions || 0);
        const ratio = Math.sqrt(Math.max(0, sessions) / maxSessions);
        map.regions[code].element.setStyle('fill', tailAdminBlue(ratio));
        lookup.set(code, {
            ...country,
            share: (sessions / totalSessions) * 100,
        });
    });

    return lookup;
}

function destroyGenerated(section) {
    const mapHost = section.querySelector('[data-ga4-tailadmin-map]');
    if (mapHost?.__ga4TailAdminMap) {
        try {
            mapHost.__ga4TailAdminMap.destroy?.();
        } catch (_) {
            // A Livewire morph may already have removed part of the SVG tree.
        }
    }

    section.querySelectorAll('[data-ga4-tailadmin-generated]').forEach((node) => node.remove());
    delete section.dataset.ga4TailadminSignature;
}

function createTailAdminChrome(data, text) {
    const { countrySection, countryChart, countries } = data;

    purgeLegacyCityMap(countrySection);
    destroyGenerated(countrySection);
    countryChart.classList.add('hidden');

    const heading = countrySection.querySelector('h4');
    if (heading) heading.textContent = text.title;

    const hint = document.createElement('p');
    hint.dataset.ga4TailadminGenerated = 'true';
    hint.className = 'mt-1 text-xs text-gray-400';
    hint.textContent = text.hint;
    heading?.insertAdjacentElement('afterend', hint);

    const mapWrapper = document.createElement('div');
    mapWrapper.dataset.ga4TailadminGenerated = 'true';
    mapWrapper.className = 'my-5 overflow-hidden rounded-2xl border border-gray-200 bg-gray-50 px-4 py-6 dark:border-gray-800 dark:bg-gray-900 sm:px-6';

    const mapHost = document.createElement('div');
    mapHost.dataset.ga4TailadminMap = 'true';
    mapHost.className = 'mapOne map-btn -mx-4 -my-6 h-[240px] w-[calc(100%+2rem)] sm:-mx-6 sm:w-[calc(100%+3rem)]';
    mapWrapper.appendChild(mapHost);
    hint.insertAdjacentElement('afterend', mapWrapper);

    const countryList = document.createElement('div');
    countryList.dataset.ga4TailadminGenerated = 'true';
    countryList.className = 'space-y-4';

    const displayedTotal = Math.max(1, countries.reduce((sum, country) => sum + Number(country.sessions || 0), 0));
    countries.slice(0, 6).forEach((country) => {
        const sessions = Number(country.sessions || 0);
        const percentage = Math.max(0, Math.min(100, (sessions / displayedTotal) * 100));
        const row = document.createElement('div');
        row.className = 'flex items-center justify-between gap-4';
        row.innerHTML = `
            <div class="min-w-0">
                <p class="truncate text-sm font-semibold text-gray-800 dark:text-white/90"></p>
                <span class="mt-0.5 block text-xs text-gray-500 dark:text-gray-400"></span>
            </div>
            <div class="flex w-full max-w-[170px] items-center gap-3">
                <div class="relative block h-2 w-full max-w-[120px] rounded-sm bg-gray-200 dark:bg-gray-800">
                    <div class="absolute left-0 top-0 h-full rounded-sm bg-brand-500" style="width:${percentage.toFixed(1)}%"></div>
                </div>
                <p class="w-11 text-right text-sm font-medium tabular-nums text-gray-800 dark:text-white/90">${percentage.toFixed(1)}%</p>
            </div>
        `;
        row.querySelector('p').textContent = country.name;
        row.querySelector('span').textContent = `${sessions.toLocaleString()} ${text.visit}`;
        countryList.appendChild(row);
    });
    mapWrapper.insertAdjacentElement('afterend', countryList);

    const status = document.createElement('p');
    status.dataset.ga4TailadminGenerated = 'true';
    status.dataset.ga4TailadminStatus = 'true';
    status.className = 'mt-3 text-[11px] text-gray-400';
    status.textContent = countries.length ? text.loading : text.noData;
    countryList.insertAdjacentElement('afterend', status);

    countrySection.dataset.ga4TailadminSignature = data.signature;

    return { mapHost, status };
}

async function mountTailAdminMap(data) {
    const text = labels();
    const { mapHost, status } = createTailAdminChrome(data, text);

    if (!data.countries.length) {
        status.textContent = text.noData;
        return;
    }

    let Constructor;
    try {
        Constructor = await ensureLibrary();
    } catch (_) {
        status.textContent = text.failed;
        return;
    }

    let countryLookup = new Map();

    try {
        const map = new Constructor({
            selector: mapHost,
            map: 'world',
            zoomButtons: false,
            regionStyle: {
                initial: {
                    fontFamily: 'Outfit',
                    fill: '#D9D9D9',
                },
                hover: {
                    fillOpacity: 1,
                    fill: '#465fff',
                },
            },
            onRegionTooltipShow(event, tooltip, code) {
                const country = countryLookup.get(String(code || '').toUpperCase());
                if (!country) return;

                tooltip.text(
                    `${country.name} · ${Number(country.sessions || 0).toLocaleString()} ${text.visit} · ${Number(country.share || 0).toLocaleString(undefined, { maximumFractionDigits: 1 })}% ${text.share}`,
                );
            },
        });

        mapHost.__ga4TailAdminMap = map;
        countryLookup = shadeCountries(map, data.countries);
        status.textContent = text.ready;
    } catch (_) {
        status.textContent = text.failed;
    }
}

function enhanceAnalysis(analysis) {
    hideCitySection(analysis);

    const data = parseCountryData(analysis);
    if (!data) return;

    hideCitySection(analysis, data.countrySection);
    purgeLegacyCityMap(data.countrySection);

    const existingMap = data.countrySection.querySelector('[data-ga4-tailadmin-map]');
    if (existingMap && data.countrySection.dataset.ga4TailadminSignature === data.signature) {
        data.countryChart.classList.add('hidden');
        return;
    }

    mountTailAdminMap(data);
}

function renderGa4CountryMaps(root = document) {
    const scope = root?.querySelectorAll ? root : document;
    const analyses = [];

    if (scope.matches?.('[data-website-ga4-analysis]')) analyses.push(scope);
    scope.querySelectorAll?.('[data-website-ga4-analysis]').forEach((analysis) => analyses.push(analysis));

    analyses.forEach((analysis) => enhanceAnalysis(analysis));
}

document.addEventListener('DOMContentLoaded', () => renderGa4CountryMaps());
document.addEventListener('livewire:navigated', () => renderGa4CountryMaps());
document.addEventListener('livewire:init', () => {
    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.updated', ({ el }) => renderGa4CountryMaps(el));
    }
});

window.MoxDopGa4CountryMap = {
    refresh: () => renderGa4CountryMaps(),
};
