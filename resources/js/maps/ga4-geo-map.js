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

function loadStylesheet(href) {
    if (document.querySelector(`link[data-ga4-geo-css="${href}"]`)) {
        return;
    }

    const link = document.createElement('link');
    link.rel = 'stylesheet';
    link.href = href;
    link.dataset.ga4GeoCss = href;
    document.head.appendChild(link);
}

function loadScript(src, ready) {
    if (ready()) {
        return Promise.resolve();
    }

    const existing = document.querySelector(`script[data-ga4-geo-script="${src}"]`);
    if (existing) {
        return new Promise((resolve, reject) => {
            existing.addEventListener('load', resolve, { once: true });
            existing.addEventListener('error', reject, { once: true });
        });
    }

    return new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.dataset.ga4GeoScript = src;
        script.addEventListener('load', resolve, { once: true });
        script.addEventListener('error', () => reject(new Error(`Could not load ${src}`)), { once: true });
        document.head.appendChild(script);
    });
}

function vectorConstructor() {
    return window.jsVectorMap || window.JsVectorMap || null;
}

function ensureVectorLibrary() {
    if (vectorLibraryPromise) {
        return vectorLibraryPromise;
    }

    vectorLibraryPromise = (async () => {
        loadStylesheet(VECTOR_CSS_URL);
        await loadScript(VECTOR_CORE_URL, () => Boolean(vectorConstructor()));
        await loadScript(VECTOR_WORLD_URL, () => Boolean(vectorConstructor()?.maps?.world));

        const Constructor = vectorConstructor();
        if (!Constructor) {
            throw new Error('jsVectorMap is unavailable.');
        }

        return Constructor;
    })();

    return vectorLibraryPromise;
}

function buildCityIndex() {
    if (cityIndexPromise) {
        return cityIndexPromise;
    }

    cityIndexPromise = fetch(CITY_DATA_URL, { cache: 'force-cache' })
        .then((response) => {
            if (!response.ok) {
                throw new Error(`City coordinate dataset returned ${response.status}`);
            }
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
                if (!country || !name || !Number.isFinite(lat) || !Number.isFinite(lng)) {
                    return;
                }

                const item = { country, name: row.name, lat, lng };
                const exactKey = `${country}|${name}`;
                if (!byCountryAndName.has(exactKey)) {
                    byCountryAndName.set(exactKey, item);
                }

                const list = byName.get(name) || [];
                list.push(item);
                byName.set(name, list);
            });

            return { byCountryAndName, byName };
        });

    return cityIndexPromise;
}

function resolveCityCoordinate(city, index) {
    const country = String(city.country_code || '').toUpperCase();
    const name = normalizeName(city.name);
    if (!name) {
        return null;
    }

    if (country) {
        const exact = index.byCountryAndName.get(`${country}|${name}`);
        if (exact) {
            return exact;
        }
    }

    const candidates = index.byName.get(name) || [];
    if (country) {
        const sameCountry = candidates.find((candidate) => candidate.country === country);
        if (sameCountry) {
            return sameCountry;
        }
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

function cityMarkers(cities, index, labels) {
    const maxSessions = Math.max(1, ...cities.map((city) => Number(city.sessions || 0)));
    const resolved = [];

    cities.forEach((city) => {
        const coordinate = resolveCityCoordinate(city, index);
        if (!coordinate) {
            return;
        }

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

function countryValues(countries) {
    const values = {};
    countries.forEach((country) => {
        const code = String(country.code || '').toUpperCase();
        const sessions = Number(country.sessions || 0);
        if (code && sessions > 0) {
            values[code] = sessions;
        }
    });
    return values;
}

function countryLookup(countries) {
    const lookup = new Map();
    countries.forEach((country) => {
        const code = String(country.code || '').toUpperCase();
        if (code) lookup.set(code, country);
    });
    return lookup;
}

function status(el, text, tone = 'muted') {
    const host = el.closest('[data-ga4-geo-card]');
    const statusEl = host?.querySelector('[data-ga4-geo-status]');
    if (!statusEl) return;

    statusEl.textContent = text || '';
    statusEl.dataset.tone = tone;
}

async function mountGeoMap(el) {
    const payloadRaw = el.getAttribute('data-ga4-geo-map') || '{}';
    if (el.__ga4GeoPayload === payloadRaw && instances.has(el)) {
        return;
    }

    if (instances.has(el)) {
        try {
            instances.get(el)?.destroy?.();
        } catch (_) {
            // Replacing a Livewire-morphed map should never block the page.
        }
        instances.delete(el);
    }

    let payload;
    try {
        payload = JSON.parse(payloadRaw);
    } catch (_) {
        status(el, 'Map data could not be read.', 'error');
        return;
    }

    const countries = Array.isArray(payload.countries) ? payload.countries : [];
    const cities = Array.isArray(payload.cities) ? payload.cities : [];
    const labels = payload.labels || {};

    if (!countries.length && !cities.length) {
        status(el, labels.noData || 'No location data for this period.');
        return;
    }

    status(el, labels.loading || 'Loading map…');

    let Constructor;
    let cityIndex = null;
    try {
        const results = await Promise.allSettled([ensureVectorLibrary(), buildCityIndex()]);
        if (results[0].status !== 'fulfilled') {
            throw results[0].reason;
        }
        Constructor = results[0].value;
        cityIndex = results[1].status === 'fulfilled' ? results[1].value : null;
    } catch (_) {
        status(el, labels.failed || 'Map could not be loaded. The lists remain available below.', 'error');
        return;
    }

    const markers = cityIndex ? cityMarkers(cities, cityIndex, labels) : [];
    const values = countryValues(countries);
    const countriesByCode = countryLookup(countries);
    const dark = document.documentElement.classList.contains('dark');

    el.innerHTML = '';

    const map = new Constructor({
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
            hover: {
                fill: '#2563eb',
                'fill-opacity': 0.92,
                cursor: 'pointer',
            },
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
            initial: {
                fill: '#f97316',
                stroke: '#ffffff',
                'stroke-width': 1.5,
                r: 5,
            },
            hover: {
                fill: '#c2410c',
                stroke: '#ffffff',
                'stroke-width': 2,
            },
        },
        onRegionTooltipShow(event, tooltip, code) {
            const country = countriesByCode.get(String(code || '').toUpperCase());
            if (!country) return;
            tooltip.text(`${country.name} · ${Number(country.sessions || 0).toLocaleString()} ${labels.sessions || 'sessions'} · ${Number(country.share || 0).toLocaleString(undefined, { maximumFractionDigits: 1 })}%`);
        },
        onMarkerTooltipShow(event, tooltip, index) {
            const marker = markers[Number(index)];
            if (!marker?.meta) return;
            const suffix = marker.meta.detail ? ` · ${marker.meta.detail}` : '';
            tooltip.text(`${marker.name}${suffix} · ${Number(marker.meta.sessions || 0).toLocaleString()} ${labels.sessions || 'sessions'}`);
        },
    });

    instances.set(el, map);
    el.__ga4GeoPayload = payloadRaw;

    const mapped = markers.length;
    if (cities.length && mapped < cities.length) {
        status(
            el,
            (labels.partialCities || '{mapped}/{total} cities shown on the map')
                .replace('{mapped}', String(mapped))
                .replace('{total}', String(cities.length)),
        );
    } else if (cities.length) {
        status(
            el,
            (labels.allCities || '{count} cities shown on the map').replace('{count}', String(mapped)),
        );
    } else {
        status(el, labels.countryOnly || 'Country intensity shown for the selected period.');
    }
}

function renderGa4GeoMaps(root = document) {
    const scope = root?.querySelectorAll ? root : document;
    scope.querySelectorAll('[data-ga4-geo-map]').forEach((el) => {
        mountGeoMap(el);
    });
}

document.addEventListener('DOMContentLoaded', () => renderGa4GeoMaps());
document.addEventListener('livewire:navigated', () => renderGa4GeoMaps());
document.addEventListener('livewire:init', () => {
    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.updated', ({ el }) => {
            renderGa4GeoMaps(el);
        });
    }
});

window.MoxDopGa4GeoMap = {
    refresh: () => renderGa4GeoMaps(),
};
