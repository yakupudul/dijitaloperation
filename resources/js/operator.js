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
    // has settled, creating (for example) a 1021px canvas inside a ~330px card.
    // Constrain both the grid item and the chart host before Apex measures them.
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

    // Force layout after applying the grid constraints, then give Apex the
    // actual host width rather than allowing it to reuse a stale full-row width.
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

function destroyOperatorChart(el) {
    disconnectChartResizeObserver(el);

    if (el.__apexChart) {
        try {
            el.__apexChart.destroy();
        } catch (_) {
            // Livewire may already have morphed part of the ApexCharts DOM.
        }
    }

    el.__apexChart = null;
    el.__apexSignature = null;
}

/**
 * Render ApexCharts from the server-owned `data-chart` payload.
 *
 * Livewire can preserve the chart host while changing only its `data-chart`
 * attribute. Therefore a one-time "already rendered" flag is incorrect: when the
 * payload changes we must destroy the old Apex instance and render the new data.
 */
function renderOperatorCharts(root = document) {
    matchingElements(root, '[data-chart]').forEach((el) => {
        const signature = el.getAttribute('data-chart') || '';
        if (!signature) {
            destroyOperatorChart(el);
            return;
        }

        if (el.__apexChart && el.__apexSignature === signature) {
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

        Promise.resolve(chart.render()).then(() => {
            if (el.__apexChart !== chart) {
                return;
            }

            if (isDeviceDistributionChart(el)) {
                bindDeviceChartResizeObserver(el);
                requestAnimationFrame(() => synchronizeDeviceChartWidth(el, chart));
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

    postMorphFrame = requestAnimationFrame(() => {
        postMorphFrame = null;
        synchronizeInteractiveViews();
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
        window.Livewire.hook('morph.updated', ({ el }) => {
            renderOperatorCharts(el);
            bindDatePickers(el);
            schedulePostMorphSynchronization();
        });
    }
});
