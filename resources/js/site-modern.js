// The Modern website design (D121): header, mobile menu, the hero's live call, the savings calculator,
// monthly/annual prices and reveal-on-scroll. No libraries; everything works without it, just stiller.
document.documentElement.classList.add('js');

const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const $ = (sel, root = document) => root.querySelector(sel);
const $$ = (sel, root = document) => [...root.querySelectorAll(sel)];

// Header shadow once the page scrolls.
const header = $('.hdr');
const onScroll = () => header?.classList.toggle('is-scrolled', window.scrollY > 8);
window.addEventListener('scroll', onScroll, { passive: true });
onScroll();

// Mobile menu.
const menuBtn = $('[data-menu]');
const menu = $('#mnav');
const setMenu = (open) => {
    menu?.classList.toggle('is-open', open);
    menuBtn?.setAttribute('aria-expanded', String(open));
    menuBtn?.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
};
menuBtn?.addEventListener('click', () => setMenu(!menu.classList.contains('is-open')));
$$('a', menu || document.createElement('div')).forEach((a) => a.addEventListener('click', () => setMenu(false)));
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') setMenu(false); });

// Hero: a short call plays out, line by line, with a running timer.
const lines = $$('.lines .line');
const timer = $('[data-timer]');
if (lines.length) {
    if (reduced) {
        lines.forEach((l) => l.classList.add('in'));
    } else {
        let i = 0;
        let seconds = 12;
        const tick = () => { if (timer) timer.textContent = `0:${String(seconds % 60).padStart(2, '0')}`; seconds = seconds >= 59 ? 12 : seconds + 1; };
        setInterval(tick, 1000);
        const step = () => {
            if (i < lines.length) {
                lines[i++].classList.add('in');
                setTimeout(step, 1700);
            } else {
                setTimeout(() => { lines.forEach((l) => l.classList.remove('in')); i = 0; setTimeout(step, 700); }, 4200);
            }
        };
        setTimeout(step, 600);
    }
}

// What missed calls cost: the visitor's own numbers, nothing assumed.
const calc = $('[data-calc]');
if (calc) {
    const money = new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
    const update = () => {
        const v = Object.fromEntries($$('input[type=range]', calc).map((input) => {
            const pct = ((input.value - input.min) / (input.max - input.min)) * 100;
            input.style.setProperty('--p', `${pct}%`);
            return [input.name, Number(input.value)];
        }));
        $('[data-out=calls]', calc).textContent = v.calls;
        $('[data-out=value]', calc).textContent = money.format(v.value);
        $('[data-out=rate]', calc).textContent = `${v.rate}%`;
        const month = v.calls * (52 / 12) * v.value * (v.rate / 100);
        $('[data-out=month]', calc).textContent = money.format(Math.round(month));
        $('[data-out=year]', calc).textContent = money.format(Math.round(month * 12));
    };
    calc.addEventListener('input', update);
    update();
}

// Pricing: monthly or annual.
const pricing = $('[data-pricing]');
$$('[data-period-btn]').forEach((btn) => btn.addEventListener('click', () => {
    pricing?.setAttribute('data-period', btn.dataset.periodBtn);
    $$('[data-period-btn]').forEach((b) => b.setAttribute('aria-pressed', String(b === btn)));
}));

// Reveal sections as they scroll into view.
const items = $$('.rv');
if (reduced || !('IntersectionObserver' in window)) {
    items.forEach((el) => el.classList.add('in'));
} else {
    const io = new IntersectionObserver((entries) => entries.forEach((entry) => {
        if (entry.isIntersecting) { entry.target.classList.add('in'); io.unobserve(entry.target); }
    }), { rootMargin: '0px 0px -8% 0px', threshold: 0.08 });
    items.forEach((el) => io.observe(el));
}
