<div>
    <x-ui.page-header title="Calendar" description="Everything on {{ $organization->name }}'s schedule, in your business's time. Each entry is tagged with where it comes from." />

    <x-client.calendar-board :organization="$organization" :sources="$sources" :any-connected="$anyConnected" :needs-reconnect="$needsReconnect" />
</div>
