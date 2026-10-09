@props(['topic' => null])
@php
    // ?topic= from the buttons around the site picks the right reason.
    $topics = ['demo' => 'demo', 'setup' => 'sales', 'sales' => 'sales', 'enterprise' => 'enterprise', 'support' => 'support'];
    $selected = old('inquiry_type', $topics[$topic] ?? 'general');
@endphp
<div id="contact-form" class="scroll-mt-28">
    @if (session('contact_success'))
        <div class="rounded-2xl border border-mint-500/30 bg-mint-200/30 p-6 text-slate-900" role="status">
            <p class="text-lg font-semibold">Thanks, we've got your message.</p>
            <p class="mt-1 text-slate-600">{{ session('contact_success') }}</p>
        </div>
    @else
        <form method="POST" action="{{ route('contact.store') }}" class="grid gap-5 sm:grid-cols-2" novalidate>
            @csrf
            {{-- Bots fill every field; people never see this one. --}}
            <div class="hidden" aria-hidden="true"><label>Leave this empty <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

            <div>
                <label for="contact_name" class="field-label">Your name</label>
                <input type="text" id="contact_name" name="name" value="{{ old('name') }}" class="field" required autocomplete="name" @error('name') aria-invalid="true" aria-describedby="err-name" @enderror>
                @error('name')<p id="err-name" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="contact_company" class="field-label">Business name <span class="font-normal text-slate-400">(optional)</span></label>
                <input type="text" id="contact_company" name="company" value="{{ old('company') }}" class="field" autocomplete="organization">
            </div>
            <div>
                <label for="contact_email" class="field-label">Email</label>
                <input type="email" id="contact_email" name="email" value="{{ old('email') }}" class="field" required autocomplete="email" @error('email') aria-invalid="true" aria-describedby="err-email" @enderror>
                @error('email')<p id="err-email" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div>
                <label for="contact_phone" class="field-label">Phone <span class="font-normal text-slate-400">(optional)</span></label>
                <input type="tel" id="contact_phone" name="phone" value="{{ old('phone') }}" class="field" autocomplete="tel">
                <p class="mt-1.5 text-xs text-slate-500">We only text you if you tick the box below.</p>
            </div>
            <div class="sm:col-span-2">
                <label for="contact_inquiry" class="field-label">How can we help?</label>
                <select id="contact_inquiry" name="inquiry_type" class="field" required>
                    @foreach (\App\Models\ContactSubmission::INQUIRY_TYPES as $value => $label)
                        <option value="{{ $value }}" @selected($selected === $value)>{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="sm:col-span-2">
                <label for="contact_message" class="field-label">Message</label>
                <textarea id="contact_message" name="message" rows="4" class="field" required placeholder="Your industry, roughly how many calls you get, and what you'd like us to handle." @error('message') aria-invalid="true" aria-describedby="err-message" @enderror>{{ old('message') }}</textarea>
                @error('message')<p id="err-message" class="mt-1.5 text-sm text-red-600">{{ $message }}</p>@enderror
            </div>
            <div class="space-y-3 sm:col-span-2">
                <label class="flex items-start gap-3 text-sm text-slate-600">
                    <input type="checkbox" id="contact_privacy" name="privacy" value="1" @checked(old('privacy')) required class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>I agree to the <a href="{{ route('legal.privacy-policy') }}" class="font-medium text-brand-600 underline underline-offset-2">Privacy Policy</a>.</span>
                </label>
                @error('privacy')<p class="text-sm text-red-600">{{ $message }}</p>@enderror
                <label class="flex items-start gap-3 text-xs leading-5 text-slate-500">
                    <input type="checkbox" id="contact_sms_consent" name="sms_consent" value="1" @checked(old('sms_consent')) class="mt-0.5 size-4 rounded border-slate-300 text-brand-600 focus:ring-brand-500">
                    <span>(Optional) I agree to receive communications by text message about inquiries, confirm appointments, schedule appointments from Sure Help Solution. You may opt-out by replying STOP or ask for more information by replying HELP. Message frequency varies. Message and data rates may apply. You may review our <a href="{{ route('legal.privacy-policy') }}" class="underline underline-offset-2">Privacy Policy</a> to learn how your data is used.</span>
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-4 sm:col-span-2">
                <button type="submit" class="btn-dark">Send message</button>
                <p class="text-sm text-slate-500">We reply within one business day.</p>
            </div>
        </form>
    @endif
</div>
