<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Estado de retiro</title>
</head>
<body style="font-family:'Segoe UI',Roboto,sans-serif;padding:24px;color:#334155;">
    @if($status === 'approved')
        <h2 style="color:#1e9f57;">Retiro aprobado</h2>
        <p>Tu solicitud #{{ $withdrawal->id }} por <strong>Bs {{ $withdrawal->monto }}</strong> fue <strong>aprobada</strong>.</p>
        <p style="color:#64748b;font-size:13px;">El pago se procesará según el método configurado en tu cuenta.</p>
    @else
        <h2 style="color:#dc2626;">Retiro rechazado</h2>
        <p>Tu solicitud #{{ $withdrawal->id }} por <strong>Bs {{ $withdrawal->monto }}</strong> fue <strong>rechazada</strong>.</p>
        @if($withdrawal->rejected_reason || $withdrawal->notas_admin)
            <p>Motivo: {{ $withdrawal->rejected_reason ?? $withdrawal->notas_admin }}</p>
        @endif
        <p style="color:#64748b;font-size:13px;">El saldo retenido fue liberado a tu billetera.</p>
    @endif
</body>
</html>
