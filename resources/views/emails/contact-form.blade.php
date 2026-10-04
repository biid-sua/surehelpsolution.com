New contact form submission
============================

Name: {{ $submission->name }}
Email: {{ $submission->email }}
Phone: {{ $submission->phone ?? '—' }}
Company: {{ $submission->company ?? '—' }}
Inquiry type: {{ $submission->inquiryLabel() }}

Message:
{{ $submission->message }}

SMS consent: {{ $submission->sms_consent ? 'Yes' : 'No' }}

---
Submitted at: {{ $submission->created_at->format('M j, Y g:i A T') }}
IP: {{ $submission->ip_address ?? '—' }}
