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

function destroyOperatorChart(el) {
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
            return;
        }

        let options;
        try {
            options = JSON.parse(signature);
        } catch (_) {
            return;
        }

        destroyOperatorChart(el);

        const chart = new ApexCharts(el, options);
        el.__apexChart = chart;
        el.__apexSignature = signature;

        Promise.resolve(chart.render()).catch(() => {
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
