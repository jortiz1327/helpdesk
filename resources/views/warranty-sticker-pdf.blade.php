@php
    // Pegatina del paquete (la de la app antigua): 105 × 74 mm. Hasta 4 productos a tamaño
    // normal; con más, dos columnas y filas más bajas para que quepan.
    $f = fn ($s) => $s ? \Carbon\Carbon::parse($s)->format('d/m/Y') : 'DD/MM/AAAA';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Pegatina {{ $d['code'] }}</title>
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 7pt; color: #000; }
    .lbl { position: absolute; width: 105mm; height: 74mm; overflow: hidden; }
    .in { padding: 3.5mm 5mm 0 5mm; }
    table.lay { width: 95mm; border-collapse: collapse; }
    table.lay td { vertical-align: top; padding: 0; width: 50%; }
    .f { margin-bottom: 2.5mm; }
    .b { font-weight: bold; text-transform: uppercase; }
    .cli p { margin: 0; }
    .small { font-size: 2.17mm; color: #555; text-transform: uppercase; }
    .chk { margin-bottom: 1.5mm; }
    .chk .t { font-weight: bold; font-size: 2.33mm; text-transform: uppercase; }
    .line { display: block; border-bottom: 0.35mm solid #999; width: 25mm; height: 3.5mm; margin-top: 0.75mm; }
    .logo { text-align: center; padding-right: 7mm !important; }
    .logo img { width: 24.65mm; height: 12.33mm; }
    .code { margin-top: 1.5mm; font-size: 7.5pt; }
    .tit { font-weight: bold; font-size: 7.2pt; margin-bottom: 0.35mm; text-transform: uppercase; }
    table.prod { width: {{ $cols === 2 ? '100%' : '75%' }}; border-collapse: collapse; border-top: 0.14mm solid #ccc; font-size: {{ $letra }}pt; }
    table.prod td { border-bottom: 0.14mm solid #ccc; padding: {{ $relleno }}mm {{ $cols === 2 ? 0.4 : 0.85 }}mm; white-space: nowrap; }
    table.prod td.q { width: {{ $cols === 2 ? 4.5 : 6 }}mm; text-align: right; }
    table.prod td.gap { width: 1.5mm; border-bottom: 0; padding: 0; }
    table.prod td.vacia { border-bottom: 0; }
</style>
</head>
<body>
@foreach ($slots as $s)
    @php $n = $s - 1; @endphp
    <div class="lbl" style="left: {{ $a4 ? ($n % 2) * 105 : 0 }}mm; top: {{ $a4 ? intdiv($n, 2) * 74 : 0 }}mm;">
        <div class="in">
            <table class="lay"><tr>
                <td>
                    <div class="f"><span class="b">Enviado por el cliente el:</span><br>{{ $f($d['shipped_at'] ?? null) }}</div>
                    <div class="f"><span class="b">Recibido en almacén:</span><br>{{ $f($d['received_at'] ?? null) }}</div>
                    <div class="f"><span class="b">Cliente:</span><br>
                        <div class="cli"><p>{{ $d['client'] ?: '—' }}</p>@if (! empty($d['company']))<p class="small">{{ $d['company'] }}</p>@endif</div>
                    </div>
                </td>
                <td>
                    @foreach (['Revisado el', 'Pte. creación presupuesto', 'Resolución enviada el'] as $t)
                        <div class="chk"><span class="t">&#11034; {{ $t }}:</span><span class="line"></span></div>
                    @endforeach
                </td>
            </tr></table>
            <table class="lay" style="margin-top: 1mm;"><tr>
                <td class="logo">
                    <img src="{{ $logo }}" alt="">
                    <div class="code">{{ $d['code'] }}</div>
                </td>
                <td>
                    <div class="tit">Productos:</div>
                    @if ($filas)
                        <table class="prod">
                            @for ($r = 0; $r < $filas; $r++)
                                <tr>
                                    @foreach ($columnas as $c => $col)
                                        @if ($c > 0)<td class="gap"></td>@endif
                                        @if (isset($col[$r]))
                                            <td>{{ $col[$r]['name'] }}</td><td class="q">{{ $col[$r]['qty'] }}</td>
                                        @else
                                            <td class="vacia"></td><td class="q vacia"></td>
                                        @endif
                                    @endforeach
                                </tr>
                            @endfor
                        </table>
                    @else
                        <p>Sin productos.</p>
                    @endif
                </td>
            </tr></table>
        </div>
    </div>
@endforeach
</body>
</html>
