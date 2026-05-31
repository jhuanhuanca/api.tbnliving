<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f2;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0f4f2;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 12px 40px rgba(15,23,42,0.08);">
                @include('emails.partials.shell-open', ['heading' => $heading])
                <p style="margin:0 0 16px;color:#334155;font-size:15px;line-height:1.6;">
                    Hola <strong>{{ $userName }}</strong>,
                </p>
                <p style="margin:0 0 16px;color:#475569;font-size:15px;line-height:1.6;">
                    {{ $intro }}
                </p>
                @if(!empty($bodyHtml))
                    {!! $bodyHtml !!}
                @endif
                @if(!empty($ctaUrl) && !empty($ctaLabel))
                    <div style="text-align:center;margin:28px 0;">
                        <a href="{{ $ctaUrl }}" style="display:inline-block;padding:14px 28px;background:#1e9f57;color:#ffffff;text-decoration:none;border-radius:10px;font-weight:600;font-size:14px;">{{ $ctaLabel }}</a>
                    </div>
                @endif
                @if(!empty($footerNote))
                    <p style="margin:24px 0 0;color:#94a3b8;font-size:12px;line-height:1.5;text-align:center;">{{ $footerNote }}</p>
                @endif
                @include('emails.partials.shell-close')
            </table>
        </td>
    </tr>
</table>
</body>
</html>
