{{-- A customer email in the business's own look (D82). Data: $brand, $subjectLine, $introLines, $actionText, $actionUrl, $outroLines. --}}
@php($text = \App\Services\Business\Branding::textOn($brand['color']))
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<title>{{ $subjectLine }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f4f7;font-family:-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2330;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f7;">
<tr><td align="center" style="padding:24px 12px;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">
        <tr><td style="height:6px;background:{{ $brand['color'] }};font-size:0;line-height:0;">&nbsp;</td></tr>
        <tr><td align="center" style="padding:28px 32px 8px;">
            @if ($brand['logo'])
                <img src="{{ $brand['logo'] }}" alt="{{ $brand['name'] }}" style="max-height:56px;max-width:220px;height:auto;border:0;">
            @else
                <p style="margin:0;font-size:20px;font-weight:700;color:#1f2330;">{{ $brand['name'] }}</p>
            @endif
        </td></tr>
        <tr><td style="padding:16px 32px 8px;font-size:15px;line-height:1.6;">
            @foreach ($introLines as $line)
                <p style="margin:0 0 14px;">{!! nl2br(e($line)) !!}</p>
            @endforeach
            @if ($actionUrl)
                <p style="margin:22px 0;text-align:center;">
                    <a href="{{ $actionUrl }}" style="display:inline-block;padding:12px 22px;border-radius:8px;background:{{ $brand['color'] }};color:{{ $text }};font-weight:600;text-decoration:none;">{{ $actionText }}</a>
                </p>
            @endif
            @foreach ($outroLines as $line)
                <p style="margin:0 0 14px;">{!! nl2br(e($line)) !!}</p>
            @endforeach
        </td></tr>
        <tr><td style="padding:16px 32px 28px;border-top:1px solid #ececf1;font-size:12px;color:#8a8fa3;text-align:center;">
            {{ $brand['name'] }}@unless ($brand['white_label']) · sent with SureHelp Solutions @endunless
        </td></tr>
    </table>
</td></tr>
</table>
</body>
</html>
