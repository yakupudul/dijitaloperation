/*
 * MoxDOP operator front-end bootstrap.
 * Alpine start + global ApexCharts, adapted from TailAdmin Laravel (MIT) resources/js/app.js.
 */
import Alpine from 'alpinejs';
import ApexCharts from 'apexcharts';

window.Alpine = Alpine;
window.ApexCharts = ApexCharts;

Alpine.start();

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
document.addEventListener('livewire:update', () => renderOperatorCharts());
