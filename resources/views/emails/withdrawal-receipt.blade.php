<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Comprobante de retiro</title>
</head>
<body style="font-family:'Segoe UI',Roboto,sans-serif;padding:24px;color:#334155;line-height:1.5;">
    <h2 style="color:#15803d;margin:0 0 12px;">Comprobante de retiro procesado</h2>
    <p>Hola {{ $customerName }},</p>
    <p>
        Tu retiro <strong>#{{ $withdrawal->id }}</strong> por
        <strong>Bs {{ number_format((float) $withdrawal->monto, 2, '.', ',') }}</strong>
        fue completado.
    </p>
    <p style="color:#64748b;font-size:13px;">
        En el adjunto encontrarás el comprobante detallado (movimientos de billetera incluidos).
        Puedes abrirlo en el navegador e imprimirlo si lo necesitas.
    </p>
    <p style="color:#64748b;font-size:13px;margin-top:24px;">TBN Living</p>
</body>
</html>
