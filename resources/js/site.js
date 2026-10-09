/*
 * The public website (D55). Alpine comes with Livewire's script on these pages; this file only
 * registers the small components they use.
 */
document.addEventListener('alpine:init', () => {
    const Alpine = window.Alpine;

    /** Header: solid once the page scrolls; menus close on Escape and on navigation. */
    Alpine.data('siteHeader', () => ({
        scrolled: false,
        mobile: false,
        menu: null,
        init() {
            const update = () => { this.scrolled = window.scrollY > 8; };
            update();
            window.addEventListener('scroll', update, { passive: true });
        },
        toggle(name) {
            this.menu = this.menu === name ? null : name;
        },
        close() {
            this.menu = null;
            this.mobile = false;
        },
    }));

    /** Pricing: monthly or annual (10% off), shown per month. */
    Alpine.data('pricing', () => ({
        annual: false,
        price(monthly) {
            return this.annual ? Math.round(monthly * 0.9) : monthly;
        },
    }));

    /** What missed calls cost: the visitor's own numbers, nothing sent anywhere. */
    Alpine.data('missedCalls', () => ({
        calls: 20,
        value: 250,
        rate: 30,
        get monthly() {
            return Math.round(this.calls * (this.rate / 100) * this.value);
        },
        get yearly() {
            return this.monthly * 12;
        },
        money(n) {
            return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 }).format(n);
        },
    }));
});
