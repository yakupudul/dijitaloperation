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

window.ApexCharts = ApexCharts;
window.flatpickr = flatpickr;

function bindDatePickers(root = document) {
    root.querySelectorAll('[data-flatpickr-range]').forEach((el) => {
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

/**
 * Render (or re-render) an ApexChart into an element carrying a JSON `data-chart`
 * options attribute. Livewire-friendly: re-initialises after morph updates.
 */
function renderOperatorCharts(root = document) {
    root.querySelectorAll('[data-chart]').forEach((el) => {
        if (el.__apexRendered) {
            return;
        }
        let options;
        try {
            options = JSON.parse(el.getAttribute('data-chart'));
        } catch (e) {
            return;
        }
        const chart = new ApexCharts(el, options);
        chart.render();
        el.__apexRendered = true;
    });
}

document.addEventListener('DOMContentLoaded', () => {
    renderOperatorCharts();
    bindDatePickers();
});
document.addEventListener('livewire:navigated', () => {
    renderOperatorCharts();
    bindDatePickers();
});
document.addEventListener('livewire:init', () => {
    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.updated', ({ el }) => {
            renderOperatorCharts(el);
            bindDatePickers(el);
        });
    }
});
