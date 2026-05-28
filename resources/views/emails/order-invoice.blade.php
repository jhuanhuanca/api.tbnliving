<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Factura</title>
</head>
<body style="font-family:'Segoe UI',Roboto,sans-serif;padding:24px;color:#334155;line-height:1.5;">
    <h2 style="color:#15803d;margin:0 0 12px;">Factura de tu compra</h2>
    <p>Hola {{ $customerName }},</p>
    <p>
        Adjuntamos la factura <strong>{{ $invoice->numero_factura }}</strong>
        por un total de <strong>Bs {{ number_format((float) $invoice->total, 2, '.', ',') }}</strong>
        correspondiente al pedido #{{ $invoice->order_id }}.
    </p>
    <p style="color:#64748b;font-size:13px;">
        Abre el archivo HTML adjunto en tu navegador para ver el detalle completo o imprimir / guardar como PDF.
    </p>
    <p style="color:#64748b;font-size:13px;margin-top:24px;">TBN Living — Gracias por tu compra.</p>
</body>
</html>
