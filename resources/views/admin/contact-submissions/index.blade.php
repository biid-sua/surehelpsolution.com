<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Contact Form Submissions - Sure Help Admin</title>

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

        .table {
            --bs-table-bg: transparent;
            --bs-table-striped-bg: transparent;
            --bs-table-hover-bg: rgba(255, 255, 255, 0.05);
            --bs-table-color: var(--text-secondary);
        }

        .table th {
            background: transparent !important;
            border: none !important;
            color: var(--text-primary) !important;
            font-weight: 600 !important;
        }

        .table td {
            background: transparent !important;
            border-color: var(--card-border) !important;
            color: var(--text-secondary) !important;
            vertical-align: middle !important;
        }

        .table tbody tr {
            background-color: transparent !important;
        }

        .table tbody tr:hover > * {
            background-color: rgba(255, 255, 255, 0.05) !important;
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

        .badge-inquiry {
            background: rgba(98, 94, 208, 0.3);
            color: #c4b5fd;
            font-weight: 500;
        }

        .message-preview {
            max-width: 280px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .container-fluid {
            padding: 2rem !important;
        }

        .page-link {
            background: var(--card-bg) !important;
            border-color: var(--card-border) !important;
            color: var(--text-secondary) !important;
        }

        .page-item.active .page-link {
            background: var(--primary-gradient) !important;
            border-color: transparent !important;
            color: white !important;
        }
    </style>
</head>
<body>
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h2><i class="fas fa-envelope me-2"></i>Contact Form Submissions</h2>
                <a href="{{ route('admin.dashboard') }}" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>Back to Dashboard
                </a>
            </div>

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">All Submissions</h5>
                    <span class="text-secondary">{{ $submissions->total() }} total</span>
                </div>
                <div class="card-body">
                    @if($submissions->count() > 0)
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Name</th>
                                        <th>Email</th>
                                        <th>Phone</th>
                                        <th>Type</th>
                                        <th>Message</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($submissions as $submission)
                                        <tr>
                                            <td>{{ $submission->created_at->format('M j, Y g:i A') }}</td>
                                            <td>{{ $submission->name }}</td>
                                            <td>
                                                <a href="mailto:{{ $submission->email }}" class="text-decoration-none" style="color: #93c5fd;">
                                                    {{ $submission->email }}
                                                </a>
                                            </td>
                                            <td>{{ $submission->phone ?? '—' }}</td>
                                            <td>
                                                <span class="badge badge-inquiry">
                                                    {{ $inquiryLabels[$submission->inquiry_type] ?? $submission->inquiry_type }}
                                                </span>
                                            </td>
                                            <td>
                                                <span class="message-preview" title="{{ $submission->message }}">
                                                    {{ Str::limit($submission->message, 60) }}
                                                </span>
                                            </td>
                                            <td>
                                                <a href="{{ route('admin.contact-submissions.show', $submission) }}" class="btn btn-primary btn-sm">
                                                    <i class="fas fa-eye me-1"></i>View
                                                </a>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-center mt-3">
                            {{ $submissions->links() }}
                        </div>
                    @else
                        <div class="text-center py-5 text-secondary">
                            <i class="fas fa-inbox fa-3x mb-3"></i>
                            <p class="mb-0">No contact form submissions yet.</p>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
