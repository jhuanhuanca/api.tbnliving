@php
    use App\Support\InvoicePrintPresenter as Presenter;

    /**
     * Fuente única de datos: $print (alias $p).
     * Si producción aún tiene vista compilada antigua o falta $print, se reconstruye desde $invoice.
     */
    if (! isset($print) || ! is_array($print) || $print === []) {
        if (! isset($invoice)) {
            throw new \RuntimeException('Vista print.invoice: se requiere $print o $invoice.');
        }
        $print = (new Presenter(
            $invoice,
            $order ?? ($invoice->relationLoaded('order') ? $invoice->order : null),
        ))->toArray();
    }

    $p = $print;
    $money = fn (string $v) => Presenter::money($v);
    $hasDiscount = bccomp((string) ($p['totals']['discount'] ?? '0'), '0', 2) === 1;
    $hasTax = bccomp((string) ($p['totals']['tax_amount'] ?? '0'), '0', 2) === 1;
    $electronic = $p['document']['electronic_status'] ?? null;
    $electronicLabel = match ($electronic) {
        'issued', 'sent', 'accepted' => 'Factura electrónica válida',
        'pending_integration' => 'Pendiente de integración SIN',
        'local_only' => 'Comprobante local',
        'failed' => 'Error en emisión electrónica',
        default => $electronic ? ucfirst(str_replace('_', ' ', $electronic)) : '—',
    };
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Factura {{ $p['document']['number'] ?? '' }}</title>
    <style>
        :root {
            --ink: #0f172a;
            --muted: #64748b;
            --line: #e2e8f0;
            --soft: #f8fafc;
            --brand: #15803d;
            --brand-light: #dcfce7;
            --accent: #0ea5e9;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: "Segoe UI", system-ui, -apple-system, Roboto, Arial, sans-serif;
            font-size: 13px;
            line-height: 1.45;
            color: var(--ink);
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .sheet {
            max-width: 210mm;
            margin: 0 auto;
            padding: 14mm 12mm;
        }
        .header {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            padding-bottom: 14px;
            border-bottom: 3px solid var(--brand);
            margin-bottom: 16px;
        }
        .brand-block .brand {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: 0.06em;
            color: var(--brand);
            text-transform: uppercase;
        }
        .brand-block .doc-type {
            margin-top: 4px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.2em;
            text-transform: uppercase;
            color: var(--muted);
        }
        .brand-block .doc-title {
            font-size: 26px;
            font-weight: 700;
            margin-top: 2px;
        }
        .issuer-meta {
            text-align: right;
            font-size: 12px;
            color: var(--muted);
            max-width: 280px;
        }
        .issuer-meta strong { color: var(--ink); }
        .issuer-meta div + div { margin-top: 3px; }
        .badge {
            display: inline-block;
            margin-top: 8px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            background: var(--brand-light);
            color: var(--brand);
        }
        .badge.warn { background: #fef3c7; color: #b45309; }
        .badge.muted { background: var(--soft); color: var(--muted); }
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
        }
        .box {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 12px 14px;
            background: var(--soft);
        }
        .box.plain { background: #fff; }
        .box h3 {
            font-size: 10px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.12em;
            color: var(--muted);
            margin-bottom: 8px;
        }
        .box p { margin: 4px 0; }
        .box .name {
            font-size: 15px;
            font-weight: 700;
            color: var(--ink);
        }
        .doc-facts {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 8px;
            margin-bottom: 16px;
        }
        .fact {
            border: 1px solid var(--line);
            border-radius: 8px;
            padding: 10px 12px;
        }
        .fact .k {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--muted);
            margin-bottom: 2px;
        }
        .fact .v {
            font-size: 14px;
            font-weight: 700;
        }
        table.items {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 14px;
            font-size: 12px;
        }
        table.items thead th {
            background: var(--ink);
            color: #fff;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            padding: 9px 8px;
            text-align: left;
        }
        table.items thead th.num { text-align: right; }
        table.items tbody td {
            padding: 9px 8px;
            border-bottom: 1px solid var(--line);
            vertical-align: top;
        }
        table.items tbody tr:nth-child(even) td { background: #fafafa; }
        table.items td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
        table.items .desc-main { font-weight: 600; }
        table.items .desc-sub {
            font-size: 10px;
            color: var(--muted);
            margin-top: 2px;
        }
        table.items .disc { color: #b45309; }
        .bottom {
            display: flex;
            justify-content: space-between;
            gap: 20px;
            align-items: flex-start;
        }
        .notes {
            flex: 1;
            font-size: 11px;
            color: var(--muted);
            max-width: 52%;
        }
        .notes h4 {
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            color: var(--ink);
            margin-bottom: 6px;
        }
        .notes ul { padding-left: 16px; }
        .notes li { margin: 3px 0; }
        .totals {
            min-width: 300px;
            border: 2px solid var(--brand);
            border-radius: 10px;
            overflow: hidden;
        }
        .totals .row {
            display: flex;
            justify-content: space-between;
            padding: 8px 14px;
            border-bottom: 1px solid var(--line);
            font-size: 12px;
        }
        .totals .row .label { color: var(--muted); }
        .totals .row .amount { font-weight: 600; font-variant-numeric: tabular-nums; }
        .totals .row.discount .amount { color: #b45309; }
        .totals .row.grand {
            background: var(--brand);
            color: #fff;
            font-size: 16px;
            font-weight: 800;
            border-bottom: none;
            padding: 12px 14px;
        }
        .totals .row.grand .label { color: rgba(255,255,255,0.9); }
        .footer {
            margin-top: 18px;
            padding-top: 12px;
            border-top: 1px dashed var(--line);
            font-size: 10px;
            color: var(--muted);
            text-align: center;
        }
        @media print {
            body { background: #fff; }
            .sheet { padding: 8mm; max-width: none; }
            .no-print { display: none !important; }
        }
        @media (max-width: 720px) {
            .header, .grid-2, .bottom, .doc-facts { grid-template-columns: 1fr; display: block; }
            .header > * + *, .grid-2 > * + *, .doc-facts > * + * { margin-top: 12px; }
            .issuer-meta { text-align: left; }
            .notes { max-width: 100%; margin-bottom: 14px; }
        }
    </style>
</head>
<body>
<div class="sheet">
    <header class="header">
        <div class="brand-block">
            <div class="brand">{{ $p['issuer']['name'] ?? 'TBN Living' }}</div>
            <div class="doc-type">Comprobante fiscal</div>
            <div class="doc-title">FACTURA</div>
            @if($electronic)
                <span class="badge @if(in_array($electronic, ['failed', 'pending_integration'], true)) warn @else muted @endif">
                    {{ $electronicLabel }}
                </span>
            @endif
        </div>
        <div class="issuer-meta">
            @if(!empty($p['issuer']['nit']))
                <div><strong>NIT emisor:</strong> {{ $p['issuer']['nit'] }}</div>
            @endif
            @if(!empty($p['issuer']['authorization']))
                <div><strong>Nº autorización:</strong> {{ $p['issuer']['authorization'] }}</div>
            @endif
            <div><strong>Moneda:</strong> {{ $p['currency_label'] ?? 'BOB' }}</div>
            <div><strong>Estado:</strong> {{ ucfirst($p['document']['status'] ?? 'emitida') }}</div>
        </div>
    </header>

    <div class="doc-facts">
        <div class="fact">
            <div class="k">Nº factura</div>
            <div class="v">{{ $p['document']['number'] ?? '—' }}</div>
        </div>
        <div class="fact">
            <div class="k">Fecha emisión</div>
            <div class="v">{{ $p['document']['date'] ?? '—' }}</div>
        </div>
        <div class="fact">
            <div class="k">Pedido</div>
            <div class="v">#{{ $p['document']['order_id'] ?? '—' }}</div>
        </div>
    </div>

    <div class="grid-2">
        <div class="box">
            <h3>Datos del cliente</h3>
            <p class="name">{{ $p['customer']['name'] ?? '—' }}</p>
            @if(!empty($p['customer']['document']))
                <p><strong>CI / NIT:</strong> {{ $p['customer']['document'] }}</p>
            @endif
            @if(!empty($p['customer']['member_code']))
                <p><strong>Código socio:</strong> {{ $p['customer']['member_code'] }}</p>
            @endif
            @if(!empty($p['customer']['email']))
                <p><strong>Correo:</strong> {{ $p['customer']['email'] }}</p>
            @endif
        </div>
        <div class="box plain">
            <h3>Referencia de operación</h3>
            @if(!empty($p['document']['order_type']))
                <p><strong>Tipo pedido:</strong> {{ $p['document']['order_type'] }}</p>
            @endif
            @if(!empty($p['document']['order_reference']))
                <p><strong>Referencia:</strong> {{ $p['document']['order_reference'] }}</p>
            @endif
            @if(!empty($p['document']['order_uuid']))
                <p><strong>UUID:</strong> <span style="font-size:10px;word-break:break-all;">{{ $p['document']['order_uuid'] }}</span></p>
            @endif
            @if(!empty($p['document']['cuf']))
                <p><strong>CUF:</strong> <span style="font-size:10px;word-break:break-all;">{{ $p['document']['cuf'] }}</span></p>
            @endif
            @if(!empty($p['document']['issued_at']))
                <p><strong>Registro sistema:</strong> {{ $p['document']['issued_at'] }}</p>
            @endif
        </div>
    </div>

    <table class="items">
        <thead>
        <tr>
            <th style="width:28px;">#</th>
            <th style="width:72px;">Código</th>
            <th>Descripción</th>
            <th class="num" style="width:44px;">Cant.</th>
            <th class="num" style="width:80px;">P. unit.</th>
            @if($hasDiscount)
                <th class="num" style="width:72px;">Desc.</th>
            @endif
            <th class="num" style="width:88px;">Importe</th>
        </tr>
        </thead>
        <tbody>
        @forelse(($p['lines'] ?? []) as $line)
            <tr>
                <td>{{ $line['index'] }}</td>
                <td><code style="font-size:10px;">{{ $line['code'] }}</code></td>
                <td>
                    <div class="desc-main">{{ $line['description'] }}</div>
                    <div class="desc-sub">{{ $line['type'] }}
                        @if(!empty($line['pv']) && bccomp((string) $line['pv'], '0', 2) === 1)
                            · PV línea: {{ number_format((float) $line['pv'], 2, '.', ',') }}
                        @endif
                        @if($hasDiscount && bccomp((string) ($line['discount'] ?? '0'), '0', 2) === 1)
                            · Lista: {{ $money($line['list_unit_price']) }}
                        @endif
                    </div>
                </td>
                <td class="num">{{ $line['quantity'] }}</td>
                <td class="num">{{ $money($line['unit_price']) }}</td>
                @if($hasDiscount)
                    <td class="num disc">
                        @if(bccomp((string) ($line['discount'] ?? '0'), '0', 2) === 1)
                            −{{ $money($line['discount']) }}
                        @else
                            —
                        @endif
                    </td>
                @endif
                <td class="num"><strong>{{ $money($line['subtotal']) }}</strong></td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $hasDiscount ? 7 : 6 }}" style="text-align:center;padding:24px;color:var(--muted);">
                    Sin ítems en la factura.
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>

    <div class="bottom">
        <div class="notes">
            <h4>Información de pago y notas</h4>
            <ul>
                @if(!empty($p['payment']['method_label']))
                    <li><strong>Forma de pago:</strong> {{ $p['payment']['method_label'] }}</li>
                @endif
                @if(!empty($p['payment']['confirmed_at']))
                    <li><strong>Pago confirmado:</strong> {{ $p['payment']['confirmed_at'] }}</li>
                @endif
                @if(!empty($p['totals']['pv_total']) && bccomp((string) $p['totals']['pv_total'], '0', 2) === 1)
                    <li><strong>PV total del pedido:</strong> {{ number_format((float) $p['totals']['pv_total'], 2, '.', ',') }} PV</li>
                @endif
                @if($hasTax && !empty($p['totals']['tax_label']))
                    <li><strong>Impuesto:</strong> {{ $p['totals']['tax_label'] }} ({{ Presenter::percent($p['totals']['tax_rate']) }})</li>
                @elseif($hasTax)
                    <li><strong>IVA / impuesto:</strong> {{ Presenter::percent($p['totals']['tax_rate']) }}</li>
                @endif
                @if(!empty($p['payment']['notes']))
                    <li><strong>Notas admin:</strong> {{ $p['payment']['notes'] }}</li>
                @endif
            </ul>
            <p style="margin-top:10px;" class="no-print">
                Para guardar como PDF: menú del navegador → Imprimir → Destino «Guardar como PDF».
            </p>
        </div>

        <div class="totals">
            @if($hasDiscount)
                <div class="row">
                    <span class="label">Subtotal bruto</span>
                    <span class="amount">{{ $money($p['totals']['gross_subtotal']) }}</span>
                </div>
                <div class="row discount">
                    <span class="label">Descuento total</span>
                    <span class="amount">−{{ $money($p['totals']['discount']) }}</span>
                </div>
            @endif
            <div class="row">
                <span class="label">Subtotal {{ $hasDiscount ? 'neto' : '' }}</span>
                <span class="amount">{{ $money($p['totals']['subtotal']) }}</span>
            </div>
            <div class="row">
                <span class="label">
                    Impuesto
                    @if(bccomp((string) ($p['totals']['tax_rate'] ?? '0'), '0', 2) === 1)
                        ({{ Presenter::percent($p['totals']['tax_rate']) }})
                    @endif
                </span>
                <span class="amount">{{ $money($p['totals']['tax_amount']) }}</span>
            </div>
            <div class="row grand">
                <span class="label">TOTAL A PAGAR</span>
                <span class="amount">{{ $money($p['totals']['total']) }}</span>
            </div>
        </div>
    </div>

    <footer class="footer">
        Documento generado por el sistema TBN Living · {{ $p['issuer']['name'] ?? 'TBN Living' }}
        @if(!empty($p['issuer']['nit']))
            · NIT {{ $p['issuer']['nit'] }}
        @endif
        · Este comprobante respalda la operación comercial registrada en el pedido #{{ $p['document']['order_id'] ?? '' }}.
    </footer>
</div>
</body>
</html>
