<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Restablecer contraseña</title>
</head>
<body style="margin:0;padding:0;background:#f0f4f2;font-family:'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f0f4f2;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 12px 40px rgba(15,23,42,0.08);">
                <tr>
                    <td style="background:linear-gradient(135deg,#1e9f57,#2ebd85);padding:28px 32px;text-align:center;">
                        <p style="margin:0;color:#ffffff;font-size:13px;letter-spacing:0.12em;text-transform:uppercase;font-weight:600;">TBN Living</p>
                        <h1 style="margin:8px 0 0;color:#ffffff;font-size:22px;font-weight:700;">Recuperación de contraseña</h1>
                    </td>
                </tr>
                <tr>
                    <td style="padding:32px;">
                        @if(!empty($userName))
                            <p style="margin:0 0 16px;color:#334155;font-size:15px;line-height:1.6;">
                                Hola <strong>{{ $userName }}</strong>,
                            </p>
                        @endif
                        <p style="margin:0 0 20px;color:#475569;font-size:15px;line-height:1.6;">
                            Usa este código de verificación para restablecer tu contraseña en el panel de socios:
                        </p>
                        <div style="text-align:center;margin:28px 0;">
                            <div style="display:inline-block;padding:18px 32px;background:#f8fafc;border:2px dashed #2ebd85;border-radius:12px;">
                                <span style="font-size:36px;font-weight:800;letter-spacing:0.35em;color:#0f172a;font-family:ui-monospace,monospace;">{{ $code }}</span>
                            </div>
                        </div>
                        <p style="margin:0 0 8px;color:#64748b;font-size:13px;text-align:center;">
                            El código expira en <strong>{{ $expiresMinutes }} minutos</strong>.
                        </p>
                        <p style="margin:24px 0 0;color:#94a3b8;font-size:12px;line-height:1.5;text-align:center;">
                            Si no solicitaste este cambio, ignora este correo. Tu contraseña no se modificará.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:20px 32px;background:#f8fafc;border-top:1px solid #e2e8f0;text-align:center;">
                        <p style="margin:0;color:#94a3b8;font-size:11px;">© {{ date('Y') }} TBN Living · Bolivia</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
