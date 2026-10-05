{{-- Monthly results report (spec RPT-02). dompdf: simple CSS only. --}}
@php
    $money = fn (?int $cents) => $cents === null ? null : \App\Support\Money::format($cents, $organization->currency);
    $change = fn (?int $pct) => $pct === null ? '' : ($pct >= 0 ? '+' : '').$pct.'% vs previous month';
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $organization->name }} · {{ $period['label'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; color: #111827; font-size: 12px; margin: 0; }
        h1 { font-size: 22px; margin: 0; }
        h2 { font-size: 14px; margin: 24px 0 8px; }
        .muted { color: #6b7280; }
        .hero { background: #eef2ff; border-radius: 10px; padding: 18px 20px; margin-top: 18px; }
        .hero .big { font-size: 30px; font-weight: bold; margin: 4px 0; }
        table.kpis { width: 100%; border-collapse: separate; border-spacing: 8px 0; margin: 14px -8px 0; }
        table.kpis td { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px; width: 25%; vertical-align: top; }
        table.kpis .n { font-size: 22px; font-weight: bold; margin: 2px 0; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list td { border-bottom: 1px solid #e5e7eb; padding: 6px 4px; }
        .right { text-align: right; }
        .small { font-size: 10px; }
    </style>
</head>
<body>
    <table style="width:100%"><tr>
        <td><h1>{{ $organization->name }}</h1><div class="muted">Your SureHelp results · {{ $period['label'] }}</div></td>
        <td class="right muted small">Prepared {{ now($organization->timezoneOrDefault())->format('M j, Y') }}</td>
    </tr></table>

    <div class="hero">
        @if ($r['revenue_cents'] !== null)
            <div class="muted">Estimated revenue from jobs we booked for you</div>
            <div class="big">{{ $money($r['revenue_cents']) }}</div>
            <div class="muted">{{ number_format($r['booked']) }} jobs booked × {{ $money($r['job_value_cents']) }} average job value</div>
        @else
            <div class="muted">This month we answered</div>
            <div class="big">{{ number_format($r['answered']) }} calls</div>
            <div class="muted">Add your average job value in the portal (Results) to see what our bookings are worth.</div>
        @endif
    </div>

    <table class="kpis"><tr>
        <td><div class="muted">Calls answered</div><div class="n">{{ number_format($r['answered']) }}</div><div class="muted small">{{ $change($r['change']['answered'] ?? null) }}</div></td>
        <td><div class="muted">Jobs booked</div><div class="n">{{ number_format($r['booked']) }}</div><div class="muted small">{{ $change($r['change']['booked'] ?? null) }}</div></td>
        <td><div class="muted">New leads</div><div class="n">{{ number_format($r['leads']) }}</div><div class="muted small">{{ $change($r['change']['leads'] ?? null) }}</div></td>
        <td><div class="muted">After-hours calls caught</div><div class="n">{{ $r['after_hours'] === null ? '—' : number_format($r['after_hours']) }}</div><div class="muted small">{{ $change($r['change']['after_hours'] ?? null) }}</div></td>
    </tr></table>

    <table style="width:100%; margin-top: 8px"><tr>
        <td style="width:50%; vertical-align:top; padding-right:12px">
            <h2>How calls ended</h2>
            <table class="list">
                @foreach ($r['outcomes'] as $o)
                    @continue($o['count'] === 0)
                    <tr><td>{{ $o['label'] }}</td><td class="right">{{ number_format($o['count']) }}</td></tr>
                @endforeach
                @if ($r['calls'] === 0)<tr><td class="muted">No calls this month.</td></tr>@endif
            </table>
        </td>
        <td style="width:50%; vertical-align:top; padding-left:12px">
            <h2>Why people called</h2>
            <table class="list">
                @forelse ($r['reasons'] as $reason => $n)
                    <tr><td>{{ $reason }}</td><td class="right">{{ number_format($n) }}</td></tr>
                @empty
                    <tr><td class="muted">No calls this month.</td></tr>
                @endforelse
            </table>
        </td>
    </tr></table>

    @if ($r['busiest'])
        <p style="margin-top:18px">Busiest time: <strong>{{ $r['busiest'] }}</strong>.</p>
    @endif
    <p class="muted small" style="margin-top:24px">Counts come from the calls and bookings recorded for you in SureHelp. Revenue is an estimate: jobs booked by our receptionists × the average job value you set.</p>
</body>
</html>
