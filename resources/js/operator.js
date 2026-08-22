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
import './maps/gbp-rank-map';
import './maps/ga4-world-map';

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

function isDeviceDistributionChart(el) {
    return el.matches?.('[aria-label="Visitor device distribution"]');
}

function prepareChartHost(el, options) {
    if (!isDeviceDistributionChart(el)) {
        return options;
    }

    // The device chart sits in a 4/12 CSS-grid column. During a Livewire morph
    // ApexCharts can otherwise measure the whole analysis width before the grid
    // has settled. Constrain the host before Apex measures it.
    el.classList.add('w-full', 'max-w-full', 'min-w-0', 'overflow-hidden');
    el.style.width = '100%';
    el.style.maxWidth = '100%';
    el.style.minWidth = '0';
    el.style.overflow = 'hidden';

    if (el.parentElement) {
        el.parentElement.classList.add('min-w-0', 'overflow-hidden');
        el.parentElement.style.minWidth = '0';
        el.parentElement.style.overflow = 'hidden';
    }

    const measuredWidth = Math.floor(el.getBoundingClientRect().width);

    return {
        ...options,
        chart: {
            ...(options.chart || {}),
            width: measuredWidth > 0 ? measuredWidth : '100%',
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

function synchronizeDeviceChartWidth(el, chart = el.__apexChart) {
    if (!isDeviceDistributionChart(el) || !chart || el.__apexChart !== chart) {
        return;
    }

    const width = Math.floor(el.getBoundingClientRect().width);
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

function bindDeviceChartResizeObserver(el) {
    if (!isDeviceDistributionChart(el) || el.__apexResizeObserver || typeof ResizeObserver === 'undefined') {
        return;
    }

    const observer = new ResizeObserver(() => {
        synchronizeDeviceChartWidth(el);
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
}

/**
 * Render ApexCharts from the server-owned `data-chart` payload.
 *
 * A Livewire date change can preserve the chart host but remove or partially
 * morph the Apex-generated SVG children. A chart object plus a matching payload
 * is therefore not enough: the rendered canvas must also still be healthy.
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

        if (el.__apexRendering && el.__apexSignature === signature) {
            return;
        }

        if (el.__apexChart && el.__apexSignature === signature && chartCanvasIsHealthy(el)) {
            if (isDeviceDistributionChart(el)) {
                bindDeviceChartResizeObserver(el);
                synchronizeDeviceChartWidth(el);
            }
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

        const chart = new ApexCharts(el, options);
        el.__apexChart = chart;
        el.__apexSignature = signature;
        el.__apexRendering = true;

        Promise.resolve(chart.render()).then(() => {
            if (el.__apexChart !== chart) {
                return;
            }

            el.__apexRendering = false;

            if (isDeviceDistributionChart(el)) {
                bindDeviceChartResizeObserver(el);
                requestAnimationFrame(() => synchronizeDeviceChartWidth(el, chart));
            }

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
