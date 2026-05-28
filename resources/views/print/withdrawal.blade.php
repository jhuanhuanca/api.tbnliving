<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Comprobante de retiro #{{ $withdrawal->id }}</title>
    <style>
        :root { --fg:#0f172a; --muted:#64748b; --border:#e2e8f0; --brand:#16a34a; --danger:#dc2626; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif; color:var(--fg); background:#fff; }
        .page { max-width: 860px; margin: 0 auto; padding: 28px; }
        .top { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; border-bottom: 2px solid var(--border); padding-bottom: 16px; }
        .brand { font-weight: 800; letter-spacing:.04em; color: var(--brand); }
        .h1 { font-size: 20px; margin: 6px 0 0; }
        .meta { text-align: right; font-size: 13px; color: var(--muted); }
        .card { border:1px solid var(--border); border-radius: 12px; padding: 12px 14px; margin-top: 16px; }
        .row { display:flex; justify-content:space-between; gap:16px; padding: 6px 0; }
        .k { color:var(--muted); }
        .v { font-weight: 700; }
        table { width:100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 10px 8px; border-bottom:1px solid var(--border); vertical-align: top; }
        th { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: .06em; text-align:left; }
        td { font-size: 14px; }
        .num { text-align:right; white-space: nowrap; }
        .badge { display:inline-block; font-size: 12px; padding: 4px 8px; border-radius: 999px; background: #f1f5f9; color: #0f172a; }
        .badge.ok { background:#dcfce7; color:#166534; }
        .badge.bad { background:#fee2e2; color: var(--danger); }
        @media print { .page { padding: 0; } }
    </style>
</head>
<body>
<div class="page">
    <div class="top">
        <div>
            <div class="brand">TBN Living</div>
            <div class="h1">Comprobante de retiro</div>
        </div>
        <div class="meta">
            <div><strong>ID:</strong> #{{ $withdrawal->id }}</div>
            <div><strong>Fecha:</strong> {{ $withdrawal->created_at?->toDateString() }}</div>
            <div>
                <strong>Estado:</strong>
                <span class="badge {{ $withdrawal->estado === 'rechazado' ? 'bad' : 'ok' }}">{{ $withdrawal->estado }}</span>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="row">
            <div class="k">Socio</div>
            <div class="v">{{ $withdrawal->user?->name }} ({{ $withdrawal->user?->member_code ?? '—' }})</div>
        </div>
        <div class="row">
            <div class="k">Monto solicitado</div>
            <div class="v">Bs {{ number_format((float) $withdrawal->monto, 2, '.', ',') }}</div>
        </div>
        <div class="row">
            <div class="k">Comisión</div>
            <div class="v">Bs {{ number_format((float) ($withdrawal->fee ?? 0), 2, '.', ',') }}</div>
        </div>
        <div class="row">
            <div class="k">Neto</div>
            <div class="v">Bs {{ number_format((float) ($withdrawal->net_amount ?? $withdrawal->monto), 2, '.', ',') }}</div>
        </div>
        <div class="row">
            <div class="k">Procesado por</div>
            <div class="v">{{ $withdrawal->processor?->name ?? '—' }}</div>
        </div>
        <div class="row">
            <div class="k">Procesado en</div>
            <div class="v">{{ $withdrawal->processed_at?->toIso8601String() ?? '—' }}</div>
        </div>
        @if($withdrawal->rejected_reason || $withdrawal->notas_admin)
            <div class="row">
                <div class="k">Motivo / notas</div>
                <div class="v">{{ $withdrawal->rejected_reason ?? $withdrawal->notas_admin }}</div>
            </div>
        @endif
    </div>

    <div class="card">
        <div class="k" style="font-weight:700; margin-bottom:8px;">Ledger asociado</div>
        <table>
            <thead>
            <tr>
                <th>ID</th>
                <th>Tipo</th>
                <th>Detalle</th>
                <th class="num">Monto</th>
                <th>Fecha</th>
            </tr>
            </thead>
            <tbody>
            @foreach(($ledger ?? []) as $row)
                <tr>
                    <td>{{ $row->id }}</td>
                    <td>{{ $row->type }}</td>
                    <td>{{ $row->description ?? '—' }}</td>
                    <td class="num">Bs {{ number_format((float) $row->amount, 2, '.', ',') }}</td>
                    <td>{{ $row->created_at?->toIso8601String() ?? '—' }}</td>
                </tr>
            @endforeach
            @if(empty($ledger) || count($ledger) === 0)
                <tr><td colspan="5" style="color:var(--muted); text-align:center; padding: 18px;">Sin movimientos asociados.</td></tr>
            @endif
            </tbody>
        </table>
    </div>

    <div style="margin-top: 16px; color:var(--muted); font-size: 12px;">
        Para PDF: usa imprimir del navegador y selecciona “Guardar como PDF”.
    </div>
</div>
</body>
</html>

