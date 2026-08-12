import maplibregl from 'maplibre-gl';
import { mapConfig, resolveMapStyle } from './config';

const instances = new WeakMap();

function rankColor(rank) {
    if (rank === null || rank === undefined || rank === '' || rank === '—') {
        return '#64748b';
    }
    const n = Number(rank);
    if (Number.isNaN(n)) {
        return '#64748b';
    }
    if (n <= 3) {
        return '#059669';
    }
    if (n <= 7) {
        return '#d97706';
    }
    if (n <= 12) {
        return '#ea580c';
    }

    return '#e11d48';
}

function parsePayload(el) {
    try {
        return JSON.parse(el.getAttribute('data-gbp-rank-map') || '{}');
    } catch {
        return null;
    }
}

function destroyMap(el) {
    const state = instances.get(el);
    if (!state) {
        return;
    }
    state.map.remove();
    instances.delete(el);
    el.__gbpMapBound = false;
}

function fitBounds(map, points, business) {
    const bounds = new maplibregl.LngLatBounds();
    let has = false;
    if (business?.lng != null && business?.lat != null) {
        bounds.extend([business.lng, business.lat]);
        has = true;
    }
    (points || []).forEach((p) => {
        if (p.lng != null && p.lat != null) {
            bounds.extend([p.lng, p.lat]);
            has = true;
        }
    });
    if (has) {
        map.fitBounds(bounds, { padding: 48, maxZoom: 13, duration: 0 });
    }
}

function buildRankMarker(point, mode, onSelect) {
    const wrap = document.createElement('button');
    wrap.type = 'button';
    wrap.className = 'gbp-rank-marker';
    wrap.setAttribute('aria-label', `Observed rank ${point.rank ?? 'unavailable'} at ${point.label || 'scan point'}`);

    const color = mode === 'change' ? changeColor(point.delta) : rankColor(point.rank);
    wrap.style.setProperty('--marker-color', color);

    const rankLabel = point.rank === null || point.rank === undefined ? '—' : String(point.rank);
    wrap.innerHTML = `<span class="gbp-rank-marker__rank">${rankLabel}</span>`;

    if (mode === 'change' && point.delta !== null && point.delta !== undefined && point.delta !== 0) {
        const delta = document.createElement('span');
        delta.className = 'gbp-rank-marker__delta';
        delta.textContent = point.delta > 0 ? `↑${point.delta}` : `↓${Math.abs(point.delta)}`;
        wrap.appendChild(delta);
    }

    wrap.addEventListener('click', (e) => {
        e.preventDefault();
        e.stopPropagation();
        onSelect(point);
    });

    return wrap;
}

function changeColor(delta) {
    if (delta === null || delta === undefined) {
        return '#64748b';
    }
    if (delta > 0) {
        return '#059669';
    }
    if (delta < 0) {
        return '#e11d48';
    }

    return '#64748b';
}

function mountMap(el) {
    if (!el || el.__gbpMapBound) {
        return;
    }
    if (el.offsetParent === null && el.getClientRects().length === 0) {
        return;
    }

    const payload = parsePayload(el);
    if (!payload?.business) {
        el.setAttribute('data-map-status', 'error');
        el.innerHTML = '<div class="gbp-map-fallback">Map could not be loaded. Use the point data table below.</div>';

        return;
    }

    el.setAttribute('data-map-status', 'loading');
    el.innerHTML = '';

    const map = new maplibregl.Map({
        container: el,
        style: resolveMapStyle(),
        center: [payload.business.lng, payload.business.lat],
        zoom: 12,
        attributionControl: false,
    });

    map.addControl(new maplibregl.NavigationControl({ showCompass: false }), 'top-right');
    map.addControl(
        new maplibregl.AttributionControl({
            compact: true,
            customAttribution: mapConfig.attribution,
        }),
        'bottom-right',
    );

    const markers = [];
    const mode = payload.mode || 'rank';

    const onSelect = (point) => {
        el.dispatchEvent(
            new CustomEvent('gbp-point-selected', {
                bubbles: true,
                detail: { id: point.id },
            }),
        );
        if (window.Livewire && el.closest('[wire\\:id]')) {
            const componentId = el.closest('[wire\\:id]')?.getAttribute('wire:id');
            if (componentId) {
                window.Livewire.find(componentId)?.call('selectPoint', point.id);
            }
        }
    };

    map.on('load', () => {
        el.setAttribute('data-map-status', 'ready');

        const bizEl = document.createElement('div');
        bizEl.className = 'gbp-business-marker';
        bizEl.title = payload.business.name || 'GBP location';
        bizEl.setAttribute('aria-label', `${payload.business.name || 'Business'} GBP location`);
        const bizMarker = new maplibregl.Marker({ element: bizEl, anchor: 'bottom' })
            .setLngLat([payload.business.lng, payload.business.lat])
            .setPopup(
                new maplibregl.Popup({ offset: 18 }).setHTML(
                    `<div class="text-xs"><strong>${payload.business.name || 'Location'}</strong><br>GBP location<br>${payload.business.address || ''}</div>`,
                ),
            )
            .addTo(map);
        markers.push(bizMarker);

        (payload.points || []).forEach((point) => {
            if (point.lng == null || point.lat == null) {
                return;
            }
            const marker = new maplibregl.Marker({
                element: buildRankMarker(point, mode, onSelect),
                anchor: 'center',
            })
                .setLngLat([point.lng, point.lat])
                .addTo(map);
            markers.push(marker);
        });

        fitBounds(map, payload.points, payload.business);
    });

    map.on('error', () => {
        if (el.getAttribute('data-map-status') !== 'ready') {
            el.setAttribute('data-map-status', 'error');
        }
    });

    const themeObserver = new MutationObserver(() => {
        const style = resolveMapStyle();
        if (map.getStyle()?.sprite !== undefined || map.isStyleLoaded()) {
            map.setStyle(style);
        }
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });

    instances.set(el, { map, markers, themeObserver, payload });
    el.__gbpMapBound = true;

    requestAnimationFrame(() => map.resize());
}

function refreshMaps(root = document) {
    root.querySelectorAll('[data-gbp-rank-map]').forEach((el) => {
        const next = el.getAttribute('data-gbp-rank-map');
        const state = instances.get(el);
        if (state && state.payloadKey === next && el.__gbpMapBound) {
            state.map.resize();

            return;
        }
        if (state) {
            state.themeObserver?.disconnect();
            destroyMap(el);
        }
        mountMap(el);
        const st = instances.get(el);
        if (st) {
            st.payloadKey = next;
        }
    });
}

export function initGbpRankMaps() {
    refreshMaps();
}

document.addEventListener('DOMContentLoaded', () => refreshMaps());
document.addEventListener('livewire:navigated', () => refreshMaps());
document.addEventListener('livewire:init', () => {
    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.updated', ({ el }) => {
            refreshMaps(el);
        });
    }
});

window.MoxDopMaps = {
    refreshGbpRankMaps: () => refreshMaps(),
    resizeGbpRankMaps: () => {
        document.querySelectorAll('[data-gbp-rank-map]').forEach((el) => {
            const state = instances.get(el);
            state?.map?.resize();
        });
    },
};
