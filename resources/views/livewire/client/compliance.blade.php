<div>
    <x-ui.page-header title="Compliance" description="HIPAA mode for healthcare practices, and the texting and email consent rules SureHelp follows for you." />

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="HIPAA mode" description="For dental, medical, therapy and other healthcare practices. Turning it on signs our Business Associate Agreement (BAA) for your business.">
            <div class="space-y-4 text-sm">
                @if ($organization->hipaa_mode)
                    <p class="flex items-center gap-2"><x-ui.badge tone="success">On</x-ui.badge> <span class="text-muted">BAA signed {{ $organization->baa_accepted_at?->timezone($organization->timezoneOrDefault())->format('M j, Y') }}</span></p>
                @else
                    <p><x-ui.badge tone="neutral">Off</x-ui.badge></p>
                @endif
                <ul class="list-disc space-y-1 pl-5 text-muted">
                    <li>No AI reads your patients' calls or messages: the AI receptionist, the AI messaging assistant, AI help for receptionists, and call transcripts and summaries are off.</li>
                    <li>Alerts with patient details stay inside SureHelp. By email, text and push you only get "new activity", and sign in to see it.</li>
                    <li>Every time someone opens a patient's record, it's written in your activity log.</li>
                    <li>Call recordings, sign-in with two-step verification and encrypted storage stay as they are.</li>
                </ul>
                @if ($canManage)
                    @if ($organization->hipaa_mode)
                        <x-ui.button type="button" variant="secondary" wire:click="disableHipaa" wire:confirm="Turn HIPAA mode off? AI features and detailed alerts come back on.">Turn HIPAA mode off</x-ui.button>
                    @else
                        <form wire:submit="enableHipaa" class="space-y-3">
                            <label class="flex items-start gap-2 text-ink">
                                <input type="checkbox" wire:model="agree" class="mt-0.5 size-4 rounded border-line-strong bg-surface-2 text-brand-500">
                                <span>I agree to the <a href="{{ route('legal.business-associate-agreement') }}" target="_blank" rel="noopener" class="font-medium text-brand-300 hover:underline">Business Associate Agreement</a> on behalf of {{ $organization->name }}.</span>
                            </label>
                            @error('agree')<p class="text-danger">{{ $message }}</p>@enderror
                            <x-ui.button type="submit" icon="shield">Turn HIPAA mode on</x-ui.button>
                        </form>
                    @endif
                @endif
            </div>
        </x-ui.card>

        <x-ui.card title="Texting and email consent" description="The US rules for texts (TCPA, A2P 10DLC) and email (CAN-SPAM), and how SureHelp keeps to them.">
            <div class="space-y-4 text-sm">
                <dl class="grid grid-cols-2 gap-3">
                    <div class="rounded-xl bg-surface-2 p-3"><dt class="text-xs text-subtle">Agreed to texts</dt><dd class="text-lg font-semibold text-ink">{{ number_format($stats['texts']) }} <span class="text-xs font-normal text-subtle">of {{ number_format($stats['customers']) }}</span></dd></div>
                    <div class="rounded-xl bg-surface-2 p-3"><dt class="text-xs text-subtle">Texted STOP</dt><dd class="text-lg font-semibold text-ink">{{ number_format($stats['stop']) }}</dd></div>
                    <div class="rounded-xl bg-surface-2 p-3"><dt class="text-xs text-subtle">Unsubscribed from offers</dt><dd class="text-lg font-semibold text-ink">{{ number_format($stats['unsubscribed']) }}</dd></div>
                    <div class="rounded-xl bg-surface-2 p-3"><dt class="text-xs text-subtle">Business texting</dt><dd class="text-lg font-semibold text-ink">{{ $textingOn ? 'Registered' : 'Not on yet' }}</dd></div>
                </dl>
                <ul class="list-disc space-y-1 pl-5 text-muted">
                    <li>Reminders, offers and campaigns are texted only to customers who agreed, and always say "Reply STOP to opt out".</li>
                    <li>Anyone who texts STOP never gets another text from your number. STOP and HELP are answered automatically.</li>
                    <li>Appointment texts answer something the customer asked for; offers never go between 8 PM and 9 AM.</li>
                    <li>Every reminder or offer email has a one-click unsubscribe link.</li>
                </ul>
                @if ($canExport)
                    <x-ui.button :href="route('app.compliance.consent-export')" variant="secondary" icon="download">Download consent records (CSV)</x-ui.button>
                @endif
            </div>
        </x-ui.card>
    </div>
</div>
