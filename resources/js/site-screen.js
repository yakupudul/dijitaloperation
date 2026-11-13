/*
 * The panel's date picker (x-operator.date-range-picker: presets, two-month calendar, comparison), the month picker
 * (x-operator.month-picker) and the website analysis chart hover. Registered on alpine:init so they exist before Livewire/Alpine walks the page.
 */
document.addEventListener('alpine:init', () => {
    Alpine.data('sitePicker', (init) => ({
        open: false, days: init.days, start: init.start, end: init.end, compare: init.compare, last: init.last || init.end, picking: 'start', view: null,
        iso(d) { return d.toISOString().slice(0, 10); },
        parse(v) { const [y, m, d] = v.split('-').map(Number); return new Date(Date.UTC(y, m - 1, d)); },
        preset(n) { this.view = null; this.days = n; const e = this.parse(this.last); this.end = this.iso(e); e.setUTCDate(e.getUTCDate() - (n - 1)); this.start = this.iso(e); },
        lastMonth() { this.view = null; const l = this.parse(this.last); const s = new Date(Date.UTC(l.getUTCFullYear(), l.getUTCMonth() - 1, 1)); const e = new Date(Date.UTC(l.getUTCFullYear(), l.getUTCMonth(), 0)); this.days = -1; this.start = this.iso(s); this.end = this.iso(e); },
        thisMonth() { this.view = null; const l = this.parse(this.last); this.days = -3; this.start = this.iso(new Date(Date.UTC(l.getUTCFullYear(), l.getUTCMonth(), 1))); this.end = this.last; },
        thisYear() { this.view = null; const l = this.parse(this.last); this.days = -4; this.start = this.iso(new Date(Date.UTC(l.getUTCFullYear(), 0, 1))); this.end = this.last; },
        custom() { this.days = 0; this.picking = 'start'; },
        all() { this.days = -2; this.apply(); },
        pick(v) {
            this.days = 0;
            if (this.picking === 'start') { this.start = v; this.end = v; this.picking = 'end'; return; }
            if (v < this.start) { this.start = v; return; }
            this.end = v; this.picking = 'start';
        },
        /** The calendar steps back and forth by a month without changing the chosen days; never past the last day's month. */
        step(by) {
            const e = this.view ? this.parse(this.view) : this.parse(this.end || this.last);
            const next = this.iso(new Date(Date.UTC(e.getUTCFullYear(), e.getUTCMonth() + by, 1)));
            this.view = next.slice(0, 7) > this.last.slice(0, 7) ? this.last : next;
        },
        months() {
            const names = ['Ocak', 'Şubat', 'Mart', 'Nisan', 'Mayıs', 'Haziran', 'Temmuz', 'Ağustos', 'Eylül', 'Ekim', 'Kasım', 'Aralık'];
            const e = this.view ? this.parse(this.view) : this.parse(this.end || this.last);
            return [-1, 0].map((off) => {
                const first = new Date(Date.UTC(e.getUTCFullYear(), e.getUTCMonth() + off, 1));
                const lead = (first.getUTCDay() + 6) % 7, count = new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth() + 1, 0)).getUTCDate();
                const cells = [];
                for (let i = 0; i < lead; i++) { cells.push({ key: 'b' + i, day: 0 }); }
                for (let d = 1; d <= count; d++) {
                    const v = this.iso(new Date(Date.UTC(first.getUTCFullYear(), first.getUTCMonth(), d)));
                    cells.push({ key: v, day: d, iso: v, future: v > this.last });
                }
                return { key: this.iso(first), title: names[first.getUTCMonth()] + ' ' + first.getUTCFullYear(), cells };
            });
        },
        cellClass(c) {
            if (!c.day) { return 'cursor-default'; }
            if (c.future) { return 'cursor-default text-gray-300 dark:text-gray-600'; }
            if (c.iso === this.start || c.iso === this.end) { return 'rounded-full bg-gray-900 font-semibold text-white dark:bg-white dark:text-gray-900'; }
            if (c.iso > this.start && c.iso < this.end) { return 'bg-gray-100 text-gray-900 dark:bg-white/10 dark:text-gray-100'; }
            return 'rounded-full text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800';
        },
        apply() {
            this.open = false;
            if (this.days === -2) { this.$wire.setRange(0, '', '', this.compare); return; }
            if (this.days > 0) { this.$wire.setRange(this.days, '', '', this.compare); return; }
            this.$wire.setRange(28, this.start, this.end, this.compare);
        },
    }));

    Alpine.data('monthPicker', (init) => ({
        open: false, month: init.month, current: init.current, future: init.future, property: init.property, year: Number(init.month.slice(0, 4)),
        key(m) { return this.year + '-' + String(m).padStart(2, '0'); },
        locked(m) { return !this.future && this.key(m) > this.current; },
        cellClass(m) {
            const k = this.key(m);
            if (this.locked(m)) { return 'cursor-default text-gray-300 dark:text-gray-600'; }
            if (k === this.month) { return 'bg-gray-900 font-semibold text-white dark:bg-white dark:text-gray-900'; }
            if (k === this.current) { return 'font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-100 dark:text-white dark:ring-gray-600 dark:hover:bg-gray-800'; }
            return 'text-gray-700 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-800';
        },
        choose(k) {
            this.open = false;
            if (k === this.month) { return; }
            this.month = k;
            this.$wire.set(this.property, k === this.current ? '' : k);
        },
    }));

    Alpine.data('siteChart', (points) => ({
        points, hover: null,
        move(event) {
            const box = event.currentTarget.getBoundingClientRect();
            const ratio = Math.min(1, Math.max(0, (event.clientX - box.left) / box.width));
            this.hover = this.points.length ? Math.round(ratio * (this.points.length - 1)) : null;
        },
        leave() { this.hover = null; },
    }));
});
