import './bootstrap';
// Heavy libraries are code-split and only downloaded on pages that use them.
const loadChart = async () => {
    const m = await import('chart.js');
    m.Chart.register(m.BarController, m.BarElement, m.CategoryScale, m.Filler, m.LinearScale, m.LineController, m.LineElement, m.PointElement, m.Tooltip);
    return m.Chart;
};

const loadCalendar = async () => {
    const [core, dayGrid, timeGrid, list] = await Promise.all([
        import('@fullcalendar/core'),
        import('@fullcalendar/daygrid'),
        import('@fullcalendar/timegrid'),
        import('@fullcalendar/list'),
    ]);
    return { Calendar: core.Calendar, plugins: [dayGrid.default, timeGrid.default, list.default] };
};

const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();

// Alpine ships with Livewire; register components before it starts.
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /**
     * <canvas x-data="chart(config)"> — config: { type, labels, series: [{ label, data }] }.
     * Re-renders when Livewire re-renders the element with new data.
     */
    Alpine.data('chart', (config) => ({
        instance: null,
        async init() {
            const Chart = await loadChart();
            const brand = css('--color-brand-400');
            const grid = css('--color-line');
            const text = css('--color-subtle');

            this.instance = new Chart(this.$refs.canvas, {
                type: config.type ?? 'bar',
                data: {
                    labels: config.labels,
                    datasets: config.series.map((series) => ({
                        label: series.label,
                        data: series.data,
                        backgroundColor: config.type === 'line' ? 'rgb(129 140 248 / 0.15)' : brand,
                        borderColor: brand,
                        borderWidth: config.type === 'line' ? 2 : 0,
                        borderRadius: 6,
                        fill: config.type === 'line',
                        tension: 0.35,
                        pointRadius: 0,
                        maxBarThickness: 28,
                    })),
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? false : { duration: 300 },
                    plugins: { legend: { display: false }, tooltip: { intersect: false, mode: 'index' } },
                    scales: {
                        x: { grid: { display: false }, ticks: { color: text, maxRotation: 0, autoSkip: true } },
                        y: { beginAtZero: true, grid: { color: grid }, ticks: { color: text, precision: 0 } },
                    },
                },
            });
        },
        destroy() {
            this.instance?.destroy();
        },
    }));

    /**
     * <div x-data="calendar(eventsUrl, sources, storageKey)"> — events are fetched from the server per
     * visible range, so the browser never holds more than what is on screen. Every event is tagged with
     * its source (SureHelp, Google, Outlook…); the chips switch sources on and off and the choice is
     * remembered per business in this browser.
     */
    Alpine.data('calendar', (eventsUrl, sources = [], storageKey = null) => ({
        instance: null,
        sources,
        hidden: [],
        tags: Object.fromEntries(sources.map((s) => [s.key, s.tag])),
        isOn(source) {
            return source.connected && !this.hidden.includes(source.key);
        },
        toggle(source) {
            if (!source.connected) return;
            this.hidden = this.hidden.includes(source.key) ? this.hidden.filter((k) => k !== source.key) : [...this.hidden, source.key];
            try { storageKey && localStorage.setItem(storageKey, JSON.stringify(this.hidden)); } catch (e) { /* private mode */ }
            this.instance?.refetchEvents();
        },
        statusText(source) {
            return { active: 'Synced', needs_reauth: 'Reconnect', error: 'Sync error' }[source.status] ?? 'Not connected';
        },
        hint(source) {
            if (source.kind !== 'external') return source.label;
            if (!source.connected) return `${source.label} isn't connected`;
            return [source.account, source.synced ? `last synced ${source.synced}` : null].filter(Boolean).join(' · ');
        },
        tag(key, extra = '') {
            const el = document.createElement('span');
            el.className = `fc-src-tag src-tag-${key} ${extra}`.trim();
            el.textContent = this.tags[key] ?? key;
            return el;
        },
        render(arg) {
            const props = arg.event.extendedProps;
            const box = document.createElement('div');
            box.className = 'fc-src-event';
            const line = document.createElement('div');
            line.className = 'fc-src-line';
            if (arg.timeText && !arg.view.type.startsWith('list')) {
                const time = document.createElement('span');
                time.className = 'fc-src-time';
                time.textContent = arg.timeText;
                line.append(time);
            }
            const title = document.createElement('span');
            title.className = 'fc-src-title';
            title.textContent = props.kind === 'busy' && props.calendar ? `Busy · ${props.calendar}` : arg.event.title;
            line.append(title);
            box.append(line);

            const tags = document.createElement('div');
            tags.className = 'fc-src-tags';
            if (props.kind === 'busy') {
                tags.append(this.tag(props.source));
            } else if (props.kind === 'appointment') {
                tags.append(this.tag('surehelp'));
                (props.synced ?? []).forEach((p) => tags.append(this.tag(p)));
                (props.conflicts ?? []).forEach((p) => {
                    const el = this.tag(p, 'is-conflict');
                    el.textContent = `Edited in ${this.tags[p] ?? p}`;
                    tags.append(el);
                });
            } else if (props.kind === 'visit') {
                tags.append(this.tag('visits'));
            }
            box.append(tags);
            box.title = [arg.event.title, props.status, props.calendar].filter(Boolean).join(' · ');

            return { domNodes: [box] };
        },
        async init() {
            try { this.hidden = (storageKey && JSON.parse(localStorage.getItem(storageKey) ?? '[]')) || []; } catch (e) { this.hidden = []; }
            const { Calendar, plugins } = await loadCalendar();
            const narrow = window.matchMedia('(max-width: 640px)').matches;
            this.instance = new Calendar(this.$refs.calendar, {
                plugins,
                initialView: narrow ? 'listWeek' : 'dayGridMonth',
                headerToolbar: {
                    left: 'prev,next today',
                    center: 'title',
                    right: narrow ? 'listWeek,dayGridMonth' : 'dayGridMonth,timeGridWeek,listWeek',
                },
                height: 'auto',
                nowIndicator: true,
                scrollTime: '07:00:00',
                dayMaxEvents: 4,
                events: {
                    url: eventsUrl,
                    extraParams: () => ({
                        sources: this.sources.filter((s) => this.isOn(s)).map((s) => s.key).join(','),
                    }),
                    failure: () => this.$dispatch('toast', { type: 'error', message: 'We could not load the calendar. Please try again.' }),
                },
                eventContent: (arg) => this.render(arg),
                eventClick: (info) => {
                    if (info.event.url) {
                        info.jsEvent.preventDefault();
                        window.location.assign(info.event.url);
                    }
                },
            });
            this.instance.render();
        },
        destroy() {
            this.instance?.destroy();
        },
    }));

    /** Toasts: window event `toast` with { type: 'success'|'error'|'info', message }. */
    Alpine.data('toasts', () => ({
        items: [],
        push(detail) {
            const id = Date.now() + Math.random();
            this.items.push({ id, type: detail.type ?? 'info', message: detail.message });
            setTimeout(() => this.dismiss(id), 5000);
        },
        dismiss(id) {
            this.items = this.items.filter((item) => item.id !== id);
        },
    }));
});
