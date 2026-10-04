<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Contact Submission - SureHelp Admin</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">

    <style>
        :root {
            --primary-gradient: linear-gradient(135deg, #1E3A8A 0%, #625ED0 50%, #4D8BCC 100%);
            --dark-bg: linear-gradient(135deg, #0f0f23 0%, #1a1a3e 50%, #2d1b69 100%);
            --card-bg: rgba(255, 255, 255, 0.05);
            --card-border: rgba(255, 255, 255, 0.1);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.7);
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            background: var(--dark-bg) !important;
            color: var(--text-primary) !important;
            min-height: 100vh !important;
        }

        .card {
            background: var(--card-bg) !important;
            backdrop-filter: blur(20px) !important;
            border: 1px solid var(--card-border) !important;
            border-radius: 16px !important;
        }

        .card-header {
            background: rgba(255, 255, 255, 0.05) !important;
            border-bottom: 1px solid var(--card-border) !important;
            color: var(--text-primary) !important;
        }

        .btn-primary {
            background: var(--primary-gradient) !important;
            border: none !important;
            border-radius: 12px !important;
            font-weight: 600 !important;
        }

        .btn-secondary {
            color: var(--text-primary) !important;
            background: rgba(255, 255, 255, 0.1) !important;
            border: 1px solid var(--card-border) !important;
        }

        .detail-label {
            color: var(--text-secondary);
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            margin-bottom: 0.25rem;
        }

        .detail-value {
            color: var(--text-primary);
            margin-bottom: 1.25rem;
        }

        .message-box {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--card-border);
            border-radius: 12px;
            padding: 1.25rem;
            white-space: pre-wrap;
            line-height: 1.6;
            color: var(--text-secondary);
        }

        .container-fluid {
            padding: 2rem !important;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-envelope-open-text me-2"></i>Contact Submission</h2>
                <div class="d-flex gap-2">
                    <a href="mailto:{{ $submission->email }}?subject=Re: Your inquiry to SureHelp Solutions" class="btn btn-primary">
                        <i class="fas fa-reply me-2"></i>Reply via Email
                    </a>
                    <a href="{{ route('admin.contact-submissions.index') }}" class="btn btn-secondary">
                        <i class="fas fa-arrow-left me-2"></i>Back to List
                    </a>
                </div>
            </div>

            <div class="row">
                <div class="col-lg-8">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Message</h5>
                        </div>
                        <div class="card-body">
                            <div class="message-box">{{ $submission->message }}</div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Contact Details</h5>
                        </div>
                        <div class="card-body">
                            <div class="detail-label">Name</div>
                            <div class="detail-value">{{ $submission->name }}</div>

                            <div class="detail-label">Email</div>
                            <div class="detail-value">
                                <a href="mailto:{{ $submission->email }}" style="color: #93c5fd;">{{ $submission->email }}</a>
                            </div>

                            <div class="detail-label">Phone</div>
                            <div class="detail-value">
                                @if($submission->phone)
                                    <a href="tel:{{ $submission->phone }}" style="color: #93c5fd;">{{ $submission->phone }}</a>
                                @else
                                    —
                                @endif
                            </div>

                            <div class="detail-label">Company</div>
                            <div class="detail-value">{{ $submission->company ?? '—' }}</div>

                            <div class="detail-label">Inquiry Type</div>
                            <div class="detail-value">
                                {{ $inquiryLabels[$submission->inquiry_type] ?? $submission->inquiry_type }}
                            </div>

                            <div class="detail-label">SMS Consent</div>
                            <div class="detail-value">{{ $submission->sms_consent ? 'Yes' : 'No' }}</div>

                            <div class="detail-label">Submitted</div>
                            <div class="detail-value">{{ $submission->created_at->format('M j, Y g:i A T') }}</div>

                            <div class="detail-label">IP Address</div>
                            <div class="detail-value mb-0">{{ $submission->ip_address ?? '—' }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
