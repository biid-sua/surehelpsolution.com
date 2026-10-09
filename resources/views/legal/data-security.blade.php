<x-site.legal
    title="Data Security"
    lead="How SureHelp protects your business's information and your customers' details"
    updated="Last Updated: October 2026"
>
{{-- Only measures SureHelp actually has in place (D55). Add a certification here only once it's been awarded. --}}
<div class="table-of-contents">
    <h2 class="toc-title">On this page</h2>
    <ul class="toc-list">
        <li><a href="#overview">Our approach</a></li>
        <li><a href="#access">Who can see your data</a></li>
        <li><a href="#accounts">Account protection</a></li>
        <li><a href="#encryption">Encryption</a></li>
        <li><a href="#application">Application security</a></li>
        <li><a href="#records">Audit trail</a></li>
        <li><a href="#privacy">Your data, your control</a></li>
        <li><a href="#healthcare">Healthcare practices</a></li>
        <li><a href="#incidents">Incidents</a></li>
        <li><a href="#contact">Report a security concern</a></li>
    </ul>
</div>

<div class="legal-card" id="overview">
    <h3 class="section-title">Our approach</h3>
    <div class="section-content">
        <p>You trust SureHelp with your calls, your customers' contact details and your calendar. We protect that information with clear limits on who can see it, strong sign-in protection, encryption and a record of who did what. This page describes the measures we have in place today. We'll update it as they grow.</p>
    </div>
</div>

<div class="legal-card" id="access">
    <h3 class="section-title">Who can see your data</h3>
    <div class="section-content">
        <ul>
            <li><strong>Your business only.</strong> Every business's information is kept separate and checked on every request. One business can never see another's calls, customers or files.</li>
            <li><strong>Only the agents serving you.</strong> A SureHelp agent can open your information only while a supervisor has assigned them to your business. When that assignment ends, their access ends immediately.</li>
            <li><strong>Least privilege for everyone.</strong> Each person's role decides exactly what they can do: your staff, your managers, our agents and our support team each see only what their work needs.</li>
            <li><strong>Private files stay private.</strong> Documents and attachments are never public. They're served only after the same access checks, every time they're opened.</li>
        </ul>
    </div>
</div>

<div class="legal-card" id="accounts">
    <h3 class="section-title">Account protection</h3>
    <div class="section-content">
        <ul>
            <li><strong>Two-step verification</strong> is required for every SureHelp staff member and agent, and available to every business user.</li>
            <li><strong>Automatic sign-out</strong> after 30 minutes without activity for SureHelp staff and agents.</li>
            <li><strong>Sign out everywhere.</strong> Changing a password, or choosing "sign out of all devices", ends every other session, including the mobile app.</li>
            <li><strong>Limits on sign-in attempts</strong> slow down password guessing.</li>
            <li><strong>Passwords are never stored</strong>, only a one-way hash of them. Nobody at SureHelp can see your password.</li>
        </ul>
    </div>
</div>

<div class="legal-card" id="encryption">
    <h3 class="section-title">Encryption</h3>
    <div class="section-content">
        <ul>
            <li><strong>In transit:</strong> all traffic to SureHelp, from the website, the portals and the mobile app, is encrypted with HTTPS.</li>
            <li><strong>Connection secrets:</strong> the keys that link your Google, Microsoft and social media accounts, and two-step verification secrets, are encrypted before they're stored and never shown in the browser or the app.</li>
        </ul>
    </div>
</div>

<div class="legal-card" id="application">
    <h3 class="section-title">Application security</h3>
    <div class="section-content">
        <ul>
            <li>Protection against cross-site request forgery on every form.</li>
            <li>Browser security headers that stop our pages being framed by other sites and limit what pages can access.</li>
            <li>Website chat, booking and contact forms only accept requests from the websites you approve, with rate limits and spam traps.</li>
            <li>Webhooks from Meta and calendar providers are verified with signatures or per-connection secrets before they're accepted.</li>
            <li>Changes to the platform are checked by an automated test suite, including tests that try to reach one business's data from another business's account.</li>
        </ul>
    </div>
</div>

<div class="legal-card" id="records">
    <h3 class="section-title">Audit trail</h3>
    <div class="section-content">
        <p>Sign-ins, changes to users and permissions, agent assignments, billing actions and other sensitive changes are recorded with who made them, when, and what changed. Passwords and secrets are never written to the record. Audit entries are kept for two years.</p>
    </div>
</div>

<div class="legal-card" id="privacy">
    <h3 class="section-title">Your data, your control</h3>
    <div class="section-content">
        <ul>
            <li><strong>Export:</strong> business owners can download a copy of their business's data from the portal.</li>
            <li><strong>Retention:</strong> you choose how long call history is kept; older records are removed automatically.</li>
            <li><strong>Erasure:</strong> a customer's personal details can be erased on request, and closing your account removes your business's data after the notice period.</li>
            <li><strong>Text messages:</strong> we only text people who have agreed to it, and every message can be stopped by replying STOP.</li>
        </ul>
        <p>See our <a href="{{ route('legal.privacy-policy') }}">Privacy Policy</a> and <a href="{{ route('legal.data-processing-addendum') }}">Data Processing Addendum</a> for the details.</p>
    </div>
</div>

<div class="legal-card" id="healthcare">
    <h3 class="section-title">Healthcare practices</h3>
    <div class="section-content">
        <p>For practices that handle protected health information, we sign a <a href="{{ route('legal.business-associate-agreement') }}">Business Associate Agreement</a> and follow its requirements.</p>
    </div>
</div>

<div class="legal-card" id="incidents">
    <h3 class="section-title">Incidents</h3>
    <div class="section-content">
        <p>If we become aware of a security incident affecting your data, we'll investigate it, contain it and tell you without undue delay, within the time limits set by applicable law and your agreements with us, with what happened, what it affects and what we're doing about it.</p>
    </div>
</div>

<div class="legal-card" id="contact">
    <h3 class="section-title">Report a security concern</h3>
    <div class="section-content">
        <p>If you think you've found a security problem, or you suspect your account has been misused, contact us straight away:</p>
        <ul>
            <li>Email: <a href="mailto:{{ config('company.email') }}">{{ config('company.email') }}</a></li>
            <li>Phone: <a href="tel:{{ config('marketing.phone_href') }}">{{ config('marketing.phone') }}</a></li>
        </ul>
        @include('partials.company-address', ['format' => 'legal'])
    </div>
</div>
</x-site.legal>
