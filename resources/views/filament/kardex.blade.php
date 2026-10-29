@php
    use Illuminate\Support\Carbon;

    $types = [
        'SALDO_INICIAL' => 'Saldo inicial', 'ENTRADA_COMPRA' => 'Compra', 'DEVOLUCION_CLIENTE' => 'Devolución de cliente',
        'AJUSTE_POSITIVO' => 'Ajuste (+)', 'SALIDA_VENTA' => 'Venta', 'MERMA' => 'Merma', 'AJUSTE_NEGATIVO' => 'Ajuste (−)',
        'DEVOLUCION_PROVEEDOR' => 'Devolución a proveedor', 'TRANSFERENCIA' => 'Transferencia',
    ];
    $fmt = static fn (string $n): string => rtrim(rtrim(number_format((float) $n, 3, '.', ','), '0'), '.') ?: '0';
    $th = 'text-align:left;padding:6px 10px;border-bottom:2px solid #d1d5db;font-size:12px;white-space:nowrap;';
    $thr = 'text-align:right;padding:6px 10px;border-bottom:2px solid #d1d5db;font-size:12px;white-space:nowrap;';
    $td = 'padding:6px 10px;border-bottom:1px solid #e5e7eb;font-size:13px;';
    $tdr = $td.'text-align:right;font-variant-numeric:tabular-nums;';
@endphp

<div style="display:grid;gap:20px;">
    <div>
        <strong>{{ $product->name }}</strong>
        <div style="font-size:13px;opacity:.75;">SKU {{ $product->sku }}
            @if ($lookup) · Costo promedio S/ {{ number_format((float) $lookup->costAvg, 2) }} @endif
        </div>
    </div>

    <div>
        <h3 style="font-weight:600;margin-bottom:6px;">Stock actual por ubicación</h3>
        @if (! $lookup || count($lookup->stock) === 0)
            <p style="font-size:13px;opacity:.75;">Sin stock en ninguna ubicación.</p>
        @else
            <table style="border-collapse:collapse;width:100%;">
                <thead><tr><th style="{{ $th }}">Almacén</th><th style="{{ $th }}">Ubicación</th><th style="{{ $thr }}">Cantidad</th></tr></thead>
                <tbody>
                @foreach ($lookup->stock as $row)
                    <tr><td style="{{ $td }}">{{ $row['warehouse'] }}</td><td style="{{ $td }}">{{ $row['location_code'] }}</td><td style="{{ $tdr }}">{{ $fmt($row['quantity']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div>
        <h3 style="font-weight:600;margin-bottom:6px;">Movimientos — saldo final {{ $fmt($kardex->closingBalance) }}</h3>
        @if ($kardex->totalRows > count($kardex->rows))
            <p data-kardex-aviso style="font-size:13px;margin-bottom:8px;padding:6px 10px;border-radius:6px;background:#fef3c7;color:#92400e;">
                Se muestran los {{ count($kardex->rows) }} movimientos más recientes de {{ $kardex->totalRows }}.
                El saldo de cada fila y el saldo final consideran el historial completo.
            </p>
        @endif
        @if (count($kardex->rows) === 0)
            <p style="font-size:13px;opacity:.75;">Este producto aún no tiene movimientos.</p>
        @else
            <div style="overflow-x:auto;">
            <table style="border-collapse:collapse;width:100%;">
                <thead>
                <tr>
                    <th style="{{ $th }}">Fecha</th><th style="{{ $th }}">Tipo</th><th style="{{ $th }}">Ubicación</th>
                    <th style="{{ $thr }}">Entra</th><th style="{{ $thr }}">Sale</th><th style="{{ $thr }}">Saldo</th>
                    <th style="{{ $thr }}">Costo</th><th style="{{ $th }}">Documento</th><th style="{{ $th }}">Usuario</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($kardex->rows as $row)
                    @php $in = (float) $row->delta > 0; @endphp
                    <tr>
                        <td style="{{ $td }}">{{ Carbon::parse($row->at)->timezone('America/Lima')->format('d/m/Y H:i') }}</td>
                        <td style="{{ $td }}">{{ $types[$row->type] ?? $row->type }}</td>
                        <td style="{{ $td }}">{{ $row->locationCode }}</td>
                        <td style="{{ $tdr }}">{{ $in ? $fmt($row->delta) : '' }}</td>
                        <td style="{{ $tdr }}">{{ $in ? '' : $fmt(ltrim($row->delta, '-')) }}</td>
                        <td style="{{ $tdr }}"><strong>{{ $fmt($row->runningBalance) }}</strong></td>
                        <td style="{{ $tdr }}">{{ $row->unitCost !== null ? number_format((float) $row->unitCost, 2) : '' }}</td>
                        <td style="{{ $td }}">{{ $row->documentRef }}</td>
                        <td style="{{ $td }}">{{ $row->userName }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
            </div>
        @endif
    </div>
</div>
