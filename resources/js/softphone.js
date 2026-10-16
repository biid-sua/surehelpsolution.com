/**
 * The agent's browser phone (D60). One tab owns the phone (Web Locks); other tabs show its state.
 * driver "browser": Twilio Voice SDK, loaded only when the agent goes ready.
 * driver "demo": no vendor; ringing calls come from the check-in and are answered in the app.
 */
const LABELS = { available: 'Ready for calls', away: 'Away', offline: 'Offline', busy: 'On a call', wrap_up: 'Finishing up' };

export default (config) => {
    // Vendor objects stay outside Alpine's reactive state (proxied, they break).
    let device = null;
    let connection = null;
    const incoming = new Map();
    const dismissed = new Set();
    // "Call" pressed in a tab that doesn't hold the phone goes to the tab that does.
    const channel = typeof BroadcastChannel === 'function' ? new BroadcastChannel('surehelp-softphone') : null;

    return {
        driver: config.driver,
        status: config.status,
        labels: LABELS,
        open: false,
        passive: false,
        ringing: [],
        active: null,
        elapsed: '0:00',
        muted: false,
        error: '',
        pollTimer: null,
        clockTimer: null,
        titleTimer: null,
        originalTitle: '',
        dialNumber: '',
        dialBusiness: config.businesses[0]?.id ?? '',

        init() {
            const start = () => this.start();
            if (navigator.locks?.request) {
                navigator.locks.request('surehelp-softphone', { ifAvailable: true }, (lock) => {
                    if (!lock) {
                        this.passive = true;
                        this.schedule();
                        this.poll();
                        // Take over when the tab that owns the phone closes.
                        navigator.locks.request('surehelp-softphone', () => { this.passive = false; start(); return new Promise(() => {}); });
                        return null;
                    }
                    start();
                    return new Promise(() => {}); // hold the lock while this tab is open
                });
            } else {
                start();
            }
            channel?.addEventListener('message', (event) => {
                if (event.data?.type === 'dial' && !this.passive) this.dial(event.data.number, event.data.business);
                if (event.data?.type === 'dialed') this.linkToCallScreen(event.data.call);
            });
            this.clockTimer = setInterval(() => this.tick(), 1000);
            window.addEventListener('beforeunload', (event) => {
                if (this.active && !this.passive) { event.preventDefault(); event.returnValue = ''; }
            });
        },

        destroy() {
            clearTimeout(this.pollTimer);
            clearInterval(this.clockTimer);
            clearInterval(this.titleTimer);
            channel?.close();
            device?.destroy();
        },

        start() {
            this.poll();
            if (this.driver === 'browser' && config.ready && this.status !== 'offline') this.connectDevice();
        },

        schedule() {
            clearTimeout(this.pollTimer);
            const fast = !this.passive && this.driver === 'demo' && this.status === 'available';
            this.pollTimer = setTimeout(() => this.poll(), fast || this.active ? 4000 : 20000);
        },

        async request(url, body = null, method = 'POST') {
            const response = await fetch(url, {
                method,
                headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '' },
                credentials: 'same-origin',
                body: body ? JSON.stringify(body) : null,
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'The phone could not reach the server.');
            return data;
        },

        callUrl(id, action) {
            return config.urls.call.replace('__ID__', id) + '/' + action;
        },

        async poll() {
            try {
                this.apply(await this.request(config.urls.checkIn));
                this.error = '';
            } catch (e) {
                this.error = e.message;
            }
            this.schedule();
        },

        apply(state) {
            this.status = state.status;
            this.active = state.active ?? (connection ? this.active : null);
            if (this.driver === 'demo') this.ringing = (state.ringing ?? []).filter((c) => !dismissed.has(c.id));
            this.alertTitle();
        },

        async setStatus(status) {
            try {
                this.apply(await this.request(config.urls.presence, { status }));
                if (this.driver === 'browser' && config.ready) {
                    if (status === 'offline') { device?.destroy(); device = null; } else if (!this.passive) { await this.connectDevice(); }
                }
            } catch (e) {
                this.error = e.message;
            }
            this.schedule();
        },

        async connectDevice() {
            if (device) return;
            try {
                const { Device } = await import('@twilio/voice-sdk');
                const token = await this.request(config.urls.token, null, 'GET');
                device = new Device(token.token, { codecPreferences: ['opus', 'pcmu'], closeProtection: true });
                device.on('incoming', (call) => this.ring(call));
                device.on('tokenWillExpire', async () => device.updateToken((await this.request(config.urls.token, null, 'GET')).token));
                device.on('error', (e) => { this.error = e.message || 'Phone error'; });
                await device.register();
            } catch (e) {
                device = null;
                this.error = 'The phone could not start: ' + (e.message || e) + '. Check your microphone permission.';
            }
        },

        ring(call) {
            const params = call.customParameters;
            const id = params.get('callId');
            incoming.set(id, call);
            this.ringing.push({ id, business: params.get('business'), business_id: params.get('businessId'), caller: params.get('caller'), number: call.parameters.From, known: false });
            const gone = () => { incoming.delete(id); this.ringing = this.ringing.filter((c) => c.id !== id); this.alertTitle(); };
            call.on('cancel', gone);
            call.on('reject', gone);
            call.on('disconnect', gone);
            this.alertTitle();
        },

        async answer(item) {
            // Opened now, inside the click, so the browser doesn't block it; filled in below.
            const onItsPage = window.location.pathname.includes('/agent/businesses/' + (item.business_id ?? '-'));
            const screen = onItsPage ? null : window.open('', 'surehelp-call-screen');
            try {
                if (this.driver === 'browser') {
                    const call = incoming.get(item.id);
                    if (!call) throw new Error('The caller hung up.');
                    call.accept();
                    connection = call;
                    this.muted = false;
                    call.on('disconnect', () => { connection = null; this.active = null; this.poll(); });
                }
                const result = await this.request(this.callUrl(item.id, 'answer'));
                this.ringing = this.ringing.filter((c) => c.id !== item.id);
                this.active = result.call;
                if (screen) {
                    screen.location.href = result.call.workspace_url;
                } else {
                    window.Livewire?.dispatch('phone-call-answered', { call: result.call.id }); // already on this business's call screen
                }
            } catch (e) {
                screen?.close();
                this.error = e.message;
                this.ringing = this.ringing.filter((c) => c.id !== item.id);
            }
            this.alertTitle();
            this.schedule();
        },

        /** Call a customer as one of the agent's businesses (D61). */
        async dial(number, business) {
            number = String(number ?? '').trim();
            business = business || this.dialBusiness;
            if (!number || !business) { this.error = 'Enter a number to call.'; this.open = true; return; }
            if (this.passive) { channel?.postMessage({ type: 'dial', number, business }); return; }
            if (this.active) { this.error = 'Finish your current call first.'; this.open = true; return; }
            this.error = '';
            try {
                const result = await this.request(config.urls.dial, { organization: business, number });
                this.active = result.call;
                this.open = false;
                this.dialNumber = '';
                this.linkToCallScreen(result.call);
                channel?.postMessage({ type: 'dialed', call: result.call });
                if (this.driver === 'browser') {
                    await this.connectDevice();
                    if (!device) throw new Error(this.error || 'The phone could not start.');
                    const call = await device.connect({ params: { callId: result.call.id } });
                    connection = call;
                    this.muted = false;
                    call.on('accept', () => this.poll());
                    call.on('disconnect', () => { connection = null; this.active = null; this.poll(); });
                    call.on('error', (e) => { this.error = e.message || 'The call failed.'; });
                }
            } catch (e) {
                this.error = e.message;
                this.open = true;
            }
            this.poll(); // status pill: "On a call"
        },

        // A call screen open for this business takes the call (number, customer, call log link).
        linkToCallScreen(call) {
            if (call?.business_id && window.location.pathname.includes('/agent/businesses/' + call.business_id)) {
                window.Livewire?.dispatch('phone-call-answered', { call: call.id });
            }
        },

        decline(item) {
            dismissed.add(item.id);
            incoming.get(item.id)?.reject();
            this.ringing = this.ringing.filter((c) => c.id !== item.id);
            this.alertTitle();
        },

        async hangUp() {
            if (!this.active) return;
            if (this.driver === 'browser') {
                connection?.disconnect();
                connection = null;
                this.active = null;
                setTimeout(() => this.poll(), 1500);
                return;
            }
            try { this.apply(await this.request(this.callUrl(this.active.id, 'hang-up'))); } catch (e) { this.error = e.message; }
        },

        toggleMute() {
            if (!connection) return;
            this.muted = !connection.isMuted();
            connection.mute(this.muted);
        },

        async transfer() {
            if (!this.active || !window.confirm('Send this caller to the business owner\'s phone? You will leave the call.')) return;
            try {
                const result = await this.request(this.callUrl(this.active.id, 'transfer'));
                window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'success', message: result.message } }));
                if (this.driver === 'demo') this.active = null;
                setTimeout(() => this.poll(), 1500);
            } catch (e) {
                this.error = e.message;
            }
        },

        async simulate(organization) {
            this.open = false;
            try { this.apply(await this.request(config.urls.simulate, { organization })); } catch (e) { this.error = e.message; this.open = true; }
            this.schedule();
        },

        tick() {
            if (!this.active?.answered_at) { this.elapsed = '0:00'; return; }
            const seconds = Math.max(0, Math.floor((Date.now() - Date.parse(this.active.answered_at)) / 1000));
            this.elapsed = Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
        },

        // A ringing call shows in the tab title, so agents notice it from other tabs.
        alertTitle() {
            const ringing = !this.passive && this.ringing.length > 0;
            if (ringing && !this.titleTimer) {
                this.originalTitle = document.title;
                let on = false;
                this.titleTimer = setInterval(() => { on = !on; document.title = on ? 'Incoming call · ' + this.originalTitle : this.originalTitle; }, 1000);
            } else if (!ringing && this.titleTimer) {
                clearInterval(this.titleTimer);
                document.title = this.originalTitle;
                this.titleTimer = null;
            }
        },
    };
};
