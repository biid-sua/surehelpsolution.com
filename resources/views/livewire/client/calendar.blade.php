<div>
    <x-ui.page-header title="Calendar" description="Service visits our agents booked for {{ $organization->name }}." />

    <x-ui.alert tone="info" class="mb-6" title="Calendar sync is coming">
        Soon you'll be able to connect Google Calendar or Microsoft Outlook, so bookings land in the calendar you already use and our agents never double-book you.
    </x-ui.alert>

    <x-ui.card>
        <div wire:ignore x-data="calendar(@js(route('app.calendar.events')))">
            <div x-ref="calendar" class="min-h-[28rem]" aria-label="Service visit calendar"></div>
        </div>
    </x-ui.card>
</div>
