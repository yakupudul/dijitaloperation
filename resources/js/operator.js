/*
 * MoxDOP operator front-end bootstrap.
 *
 * ApexCharts global + chart helpers adapted from TailAdmin Laravel (MIT).
 * Do NOT Alpine.start() here — Livewire 3 ships Alpine and starts it. Starting a
 * second Alpine instance breaks wire:click / Livewire updates on operator pages.
 * Theme + sidebar Alpine stores are registered via alpine:init in the layout.
 */
import ApexCharts from 'apexcharts';
import flatpickr from 'flatpickr';
import 'flatpickr/dist/flatpickr.min.css';
import 'maplibre-gl/dist/maplibre-gl.css';
import './maps/ga4-world-map';
import { bindSortableTables } from './sortable-tables';
import './site-screen';

window.ApexCharts = ApexCharts;
window.flatpickr = flatpickr;

let postMorphFrame = null;
let postMorphVerificationTimer = null;

function matchingElements(root, selector) {
    const scope = root?.querySelectorAll ? root : document;
    const elements = [];

    if (scope.matches?.(selector)) {
        elements.push(scope);
    }

    scope.querySelectorAll?.(selector).forEach((element) => elements.push(element));

    return elements;
}

function bindDatePickers(root = document) {
    matchingElements(root, '[data-flatpickr-range]').forEach((el) => {
        if (el.__fpBound) {
            return;
        }

        flatpickr(el, {
            mode: 'range',
            dateFormat: 'Y-m-d',
            allowInput: true,
            onClose: (selectedDates, dateStr) => {
                el.dispatchEvent(new CustomEvent('demo-range-selected', {
                    bubbles: true,
                    detail: { dateStr, selectedDates },
                }));
            },
        });
        el.__fpBound = true;
    });
}

function chartHostWidth(el) {
    return Math.floor(el.getBoundingClientRect().width || 0);
}

function prepareChartHost(el, options) {
    // Every chart can live inside an Alpine x-show tab or a responsive grid.
    // Keep the host constrained to its real parent width before Apex measures it.
    el.classList.add('w-full', 'max-w-full', 'min-w-0', 'overflow-hidden');
    el.style.maxWidth = '100%';
    el.style.minWidth = '0';
    el.style.overflow = 'hidden';

    if (el.parentElement) {
        el.parentElement.classList.add('min-w-0');
        el.parentElement.style.minWidth = '0';
    }

    const measuredWidth = chartHostWidth(el);

    return {
        ...options,
        chart: {
            ...(options.chart || {}),
            ...(measuredWidth > 0 ? { width: measuredWidth } : {}),
            redrawOnParentResize: true,
            redrawOnWindowResize: true,
        },
    };
}

function disconnectChartResizeObserver(el) {
    if (!el.__apexResizeObserver) {
        return;
    }

    el.__apexResizeObserver.disconnect();
    el.__apexResizeObserver = null;
    el.__apexObservedWidth = null;
}

function synchronizeChartWidth(el, chart = el.__apexChart) {
    if (!chart || el.__apexChart !== chart || !el.isConnected) {
        return;
    }

    const width = chartHostWidth(el);
    if (width <= 0 || el.__apexObservedWidth === width) {
        return;
    }

    el.__apexObservedWidth = width;

    Promise.resolve(chart.updateOptions({
        chart: {
            width,
            redrawOnParentResize: true,
            redrawOnWindowResize: true,
        },
    }, false, false, false)).catch(() => {
        // A concurrent Livewire morph may have replaced the host already.
    });
}

function bindChartResizeObserver(el) {
    if (el.__apexResizeObserver || typeof ResizeObserver === 'undefined') {
        return;
    }

    const observer = new ResizeObserver((entries) => {
        const width = Math.floor(entries[0]?.contentRect?.width || chartHostWidth(el));
        if (width <= 0) {
            return;
        }

        if (el.__apexChart) {
            synchronizeChartWidth(el);
            return;
        }

        // Hidden Alpine tabs start at width 0. When x-show makes the tab visible,
        // the ResizeObserver becomes the deterministic signal to instantiate the
        // chart with a real width instead of leaving a permanent 0px canvas.
        schedulePostMorphSynchronization();
    });

    observer.observe(el);
    el.__apexResizeObserver = observer;
}

function chartCanvasIsHealthy(el) {
    const canvas = el.querySelector(':scope > .apexcharts-canvas');
    const svg = canvas?.querySelector('.apexcharts-svg');

    if (!canvas || !svg) {
        return false;
    }

    const rect = canvas.getBoundingClientRect();
    const width = Number(svg.getAttribute('width') || rect.width || 0);
    const height = Number(svg.getAttribute('height') || rect.height || 0);

    return width > 0 && height > 0;
}

function destroyOperatorChart(el) {
    disconnectChartResizeObserver(el);

    if (el.__apexChart) {
        try {
            el.__apexChart.destroy();
        } catch (_) {
            // Livewire may already have morphed part of the ApexCharts DOM.
        }
    }

    // Apex may have lost its internal reference after a Livewire morph while its
    // generated canvas still remains. Remove only Apex-generated children; the
    // host and its server-owned data-chart attribute remain untouched.
    el.querySelectorAll(':scope > .apexcharts-canvas').forEach((canvas) => canvas.remove());

    el.__apexChart = null;
    el.__apexSignature = null;
    el.__apexRendering = false;
    el.__apexObservedWidth = null;
}

/**
 * Render ApexCharts from the server-owned `data-chart` payload.
 *
 * Hidden Alpine tabs have a real width of 0. ApexCharts must not be instantiated
 * while a host is hidden because it will otherwise keep a 0px SVG even after the
 * tab becomes visible. We observe every chart host and render only after it has a
 * measurable width. This also covers Livewire morphs and responsive grid changes.
 */
function renderOperatorCharts(root = document) {
    matchingElements(root, '[data-chart]').forEach((el) => {
        if (!el.isConnected) {
            return;
        }

        const signature = el.getAttribute('data-chart') || '';
        if (!signature) {
            destroyOperatorChart(el);
            return;
        }

        bindChartResizeObserver(el);

        // x-show / display:none hosts report width 0. Do not create Apex yet.
        // ResizeObserver and the window resize synchronization below will retry
        // as soon as the tab becomes visible and obtains a real width.
        if (chartHostWidth(el) <= 0) {
            return;
        }

        if (el.__apexRendering && el.__apexSignature === signature) {
            return;
        }

        if (el.__apexChart && el.__apexSignature === signature && chartCanvasIsHealthy(el)) {
            synchronizeChartWidth(el);
            return;
        }

        let options;
        try {
            options = JSON.parse(signature);
        } catch (_) {
            return;
        }

        destroyOperatorChart(el);
        options = prepareChartHost(el, options);
        bindChartResizeObserver(el);

        const chart = new ApexCharts(el, options);
        el.__apexChart = chart;
        el.__apexSignature = signature;
        el.__apexRendering = true;
        el.__apexObservedWidth = chartHostWidth(el);

        Promise.resolve(chart.render()).then(() => {
            if (el.__apexChart !== chart) {
                return;
            }

            el.__apexRendering = false;

            requestAnimationFrame(() => synchronizeChartWidth(el, chart));

            // Verify once more after Apex has committed its SVG. If Livewire
            // removed it during the same update cycle, the delayed global pass
            // below will rebuild it.
            if (!chartCanvasIsHealthy(el)) {
                schedulePostMorphSynchronization();
            }
        }).catch(() => {
            if (el.__apexChart === chart) {
                destroyOperatorChart(el);
            }
        });
    });
}

function synchronizeInteractiveViews() {
    renderOperatorCharts(document);
    bindDatePickers(document);
    bindSortableTables(document);
    window.MoxDopGa4CountryMap?.refresh?.();
}

function schedulePostMorphSynchronization() {
    if (postMorphFrame !== null) {
        cancelAnimationFrame(postMorphFrame);
    }

    if (postMorphVerificationTimer !== null) {
        clearTimeout(postMorphVerificationTimer);
    }

    // Do not instantiate charts inside Livewire's morph.updated callback. Wait
    // until the browser has committed the final morphed DOM and grid widths.
    postMorphFrame = requestAnimationFrame(() => {
        postMorphFrame = requestAnimationFrame(() => {
            postMorphFrame = null;
            synchronizeInteractiveViews();

            // A short verification pass catches Apex SVG children that were
            // removed by a late morph or a layout transition in the same update.
            postMorphVerificationTimer = setTimeout(() => {
                postMorphVerificationTimer = null;
                synchronizeInteractiveViews();
            }, 80);
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    synchronizeInteractiveViews();
});
document.addEventListener('livewire:navigated', () => {
    schedulePostMorphSynchronization();
});

// Alpine tab switchers already dispatch a resize after x-show changes state.
// Route that signal through the same debounced synchronization used by Livewire.
window.addEventListener('resize', () => {
    schedulePostMorphSynchronization();
}, { passive: true });

document.addEventListener('livewire:init', () => {
    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.updated', () => {
            // Only schedule here. Rendering during the morph can race with
            // Livewire deleting Apex-generated children from another node.
            schedulePostMorphSynchronization();
        });

        // A commit-level success hook gives us one more deterministic signal that
        // the date-filter request has finished. Not every update morphs every
        // chart host, so relying on element-level hooks alone is insufficient.
        window.Livewire.hook('commit', ({ succeed }) => {
            succeed(() => {
                schedulePostMorphSynchronization();
            });
        });
    }
});

/*
 * Live feedback for every operator action (no per-button markup):
 *  - the clicked button/link shows a spinner while its request runs (Livewire sets data-loading on it) and a short
 *    ✓ flash when it finishes;
 *  - a thin progress bar runs at the top while a request the operator started is in flight (polls are left out);
 *  - a component's `message` property (the "… kuyruğa alındı" / "… kaydedildi" lines) also pops up as a toast, so the
 *    result is seen even when the line is far from the button.
 */
const USER_ACTION_WINDOW_MS = 800;
let lastUserAction = 0;
let lastClicked = null;
let inFlight = 0;
let barTimer = null;

function progressBar() {
    let bar = document.getElementById('op-progress');
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'op-progress';
        bar.setAttribute('aria-hidden', 'true');
        document.body.appendChild(bar);
    }

    return bar;
}

function showBar() {
    clearTimeout(barTimer);
    barTimer = setTimeout(() => progressBar().classList.add('is-active'), 120);
}

function hideBar() {
    clearTimeout(barTimer);
    const bar = document.getElementById('op-progress');
    if (bar && bar.classList.contains('is-active')) {
        bar.classList.add('is-done');
        setTimeout(() => bar.classList.remove('is-active', 'is-done'), 350);
    }
}

function noticeTone(message, data) {
    const tone = data?.messageTone ?? data?.tone;
    if (tone === 'error' || tone === 'warning') {
        return 'error';
    }

    return /(hata|yapılamadı|başarısız|bulunamadı|eklenemedi|kaydedilemedi|başlatılamadı|olmadı)/i.test(message) ? 'error' : 'success';
}

['click', 'keydown', 'submit', 'change'].forEach((type) => {
    document.addEventListener(type, (event) => {
        if (type === 'keydown' && event.key !== 'Enter') {
            return;
        }
        lastUserAction = Date.now();
        if (type === 'click') {
            lastClicked = event.target.closest?.('button, a, [wire\\:click]') ?? null;
        }
    }, true);
});

document.addEventListener('livewire:init', () => {
    if (!window.Livewire?.interceptMessage) {
        return;
    }
    window.Livewire.interceptMessage(({ message, onSuccess, onError, onFinish }) => {
        const calls = Array.from(message.actions ?? []).filter((action) => !String(action.name ?? '').startsWith('$'));
        if (Date.now() - lastUserAction > USER_ACTION_WINDOW_MS || calls.length === 0) {
            return;
        }
        const clicked = lastClicked;
        inFlight++;
        showBar();
        onFinish(() => {
            inFlight = Math.max(0, inFlight - 1);
            if (inFlight === 0) {
                hideBar();
            }
        });
        onSuccess(({ payload }) => {
            let data = {};
            try {
                const snapshot = payload?.snapshot;
                data = (typeof snapshot === 'string' ? JSON.parse(snapshot) : snapshot)?.data ?? {};
            } catch (error) {
                data = {};
            }
            const after = data.message;
            if (typeof after === 'string' && after.trim() !== '') {
                window.dispatchEvent(new CustomEvent('operator-notice', { detail: { message: after, tone: noticeTone(after, data) } }));
            }
            // The action may have queued AI work: the header indicator refreshes now (a moment later, after the dispatch).
            setTimeout(() => window.Livewire?.dispatch?.('ai-live-refresh'), 600);
            if (clicked && clicked.isConnected) {
                clicked.setAttribute('data-done', '');
                setTimeout(() => clicked.removeAttribute('data-done'), 1200);
            }
        });
        onError(() => {
            window.dispatchEvent(new CustomEvent('operator-notice', { detail: { message: 'İşlem tamamlanamadı. Sayfayı yenileyip tekrar deneyin.', tone: 'error' } }));
        });
    });
});
