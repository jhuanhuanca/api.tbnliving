<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Retiro registrado</title>
</head>
<body style="font-family:'Segoe UI',Roboto,sans-serif;padding:24px;color:#334155;">
    <h2 style="color:#1e9f57;">Solicitud de retiro registrada</h2>
    <p>Hola {{ $withdrawal->user->name ?? 'Socio' }},</p>
    <p>Recibimos tu solicitud de retiro por <strong>Bs {{ $withdrawal->monto }}</strong> (estado: pendiente de revisión).</p>
    @if($withdrawal->fee > 0)
        <p>Comisión: Bs {{ $withdrawal->fee }} · Neto estimado: Bs {{ $withdrawal->net_amount }}</p>
    @endif
    <p style="color:#64748b;font-size:13px;">Un administrador la aprobará o rechazará. Te notificaremos por correo.</p>
</body>
</html>
