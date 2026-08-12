/*
 * MoxDOP operator front-end bootstrap.
 *
 * ApexCharts global + chart helpers adapted from TailAdmin Laravel (MIT).
 * Do NOT Alpine.start() here — Livewire 3 ships Alpine and starts it. Starting a
 * second Alpine instance breaks wire:click / Livewire updates on operator pages.
 * Theme + sidebar Alpine stores are registered via alpine:init in the layout.
 */
import ApexCharts from 'apexcharts';

window.ApexCharts = ApexCharts;

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

document.addEventListener('DOMContentLoaded', () => renderOperatorCharts());
document.addEventListener('livewire:navigated', () => renderOperatorCharts());
document.addEventListener('livewire:init', () => {
    if (window.Livewire?.hook) {
        window.Livewire.hook('morph.updated', ({ el }) => {
            renderOperatorCharts(el);
        });
    }
});
