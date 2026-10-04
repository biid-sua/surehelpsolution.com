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
     * <div x-data="calendar(eventsUrl)"> — events are fetched from the server per visible range,
     * so the browser never holds more than what is on screen.
     */
    Alpine.data('calendar', (eventsUrl) => ({
        instance: null,
        async init() {
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
                events: { url: eventsUrl, failure: () => this.$dispatch('toast', { type: 'error', message: 'We could not load the calendar. Please try again.' }) },
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
