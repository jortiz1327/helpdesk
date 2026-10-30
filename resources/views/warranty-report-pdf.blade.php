@php
    $eur = fn ($n) => number_format((float) $n, 2, ',', '.') . ' €';
    $color = ['correcta' => '#16a34a', 'bateria' => '#d97706', 'recuperada' => '#2563eb', 'reparada' => '#6366f1', 'no_reproduce' => '#8b5cf6', 'defectuosa' => '#dc2626', 'danada' => '#ea580c', 'desechada' => '#64748b'];
    $fotoTipo = ['front' => 'Frontal', 'back_batteries' => 'Trasera con pilas', 'back_no_batteries' => 'Trasera sin pilas', 'back' => 'Trasera'];
    $total = $items->count();
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        @page { margin: 34px 38px 46px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1e2833; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        .top td { vertical-align: top; }
        .empresa b { font-size: 15px; }
        .empresa div { color: #5a6b78; font-size: 9.5px; line-height: 1.6; }
        .tipo { text-align: right; }
        .tipo h1 { margin: 0; font-size: 18px; color: #2563eb; }
        .tipo .n { font-size: 11.5px; font-weight: bold; margin-top: 2px; }
        .tipo .f { color: #5a6b78; font-size: 9.5px; margin-top: 4px; }
        .datos { margin: 16px 0 12px; }
        .datos td { padding: 8px 11px; background: #f4f6f8; border: 3px solid #fff; vertical-align: top; line-height: 1.6; }
        .datos small { color: #5a6b78; text-transform: uppercase; font-size: 8px; letter-spacing: 0.5px; display: block; }
        h2 { font-size: 12px; margin: 18px 0 7px; padding-bottom: 4px; border-bottom: 1px solid #dde2e6; }
        .res td { padding: 4px 6px; border-bottom: 1px solid #eef1f3; }
        .res .r { text-align: right; width: 60px; font-weight: bold; }
        .dot { display: inline-block; width: 7px; height: 7px; border-radius: 4px; margin-right: 6px; }
        .bar { height: 6px; background: #eef1f3; border-radius: 3px; }
        .bar i { display: block; height: 6px; background: #2563eb; border-radius: 3px; }
        .dev th { background: #1e2833; color: #fff; font-size: 8px; text-transform: uppercase; letter-spacing: 0.4px; padding: 5px 6px; text-align: left; }
        .dev td { padding: 5px 6px; border-bottom: 1px solid #e3e7eb; vertical-align: top; font-size: 9px; }
        .mono { font-family: 'DejaVu Sans Mono', monospace; font-weight: bold; }
        .det { color: #5a6b78; font-size: 8.5px; margin-top: 2px; }
        .cov { font-size: 8px; font-weight: bold; padding: 1px 5px; border-radius: 3px; white-space: nowrap; }
        .cov.garantia { background: #dcfce7; color: #166534; }
        .cov.cliente { background: #ffedd5; color: #9a3412; }
        .cov.pilas { background: #fef3c7; color: #92400e; }
        .foto { page-break-inside: avoid; margin-bottom: 12px; }
        .foto .h { font-size: 9.5px; margin-bottom: 4px; }
        .foto td { width: 33%; padding: 3px; vertical-align: top; text-align: center; }
        .foto img { max-width: 100%; max-height: 150px; border: 1px solid #dde2e6; }
        .foto small { display: block; color: #6b7a86; font-size: 7.5px; margin-top: 2px; }
        .foot { position: fixed; bottom: -28px; left: 0; right: 0; font-size: 8px; color: #93a3ac; text-align: center; }
    </style>
</head>
<body>
    <table class="top"><tr>
        <td class="empresa">
            <b>{{ $company['name'] ?? 'AEME' }}</b>
            <div>
                @if(!empty($company['tax_id'])) CIF {{ $company['tax_id'] }}<br>@endif
                @if(!empty($company['address'])) {!! nl2br(e($company['address'])) !!}<br>@endif
                {{ collect([$company['email'] ?? null, $company['phone'] ?? null])->filter()->implode(' · ') }}
            </div>
        </td>
        <td class="tipo">
            <h1>INFORME DE RESOLUCIÓN</h1>
            <div class="n">{{ $number }}</div>
            <div class="f">Fecha: {{ now()->format('d/m/Y') }}</div>
        </td>
    </tr></table>

    <table class="datos"><tr>
        <td style="width:40%"><small>Cliente</small><b>{{ $w->client_name ?: '—' }}</b>@if($w->project_name)<br>{{ $w->project_name }}@endif</td>
        <td style="width:30%"><small>Expediente</small><b>{{ $w->code }}</b><br>Recibida el {{ $w->received_at?->format('d/m/Y') }}</td>
        <td style="width:30%"><small>Dispositivos</small><b>{{ $total }}</b> revisados</td>
    </tr></table>

    <table><tr>
        <td style="width:58%;vertical-align:top;padding-right:14px">
            <h2>Resultado de la revisión</h2>
            <table class="res">
                @foreach($res as $k => $r)
                    @if(!empty($porRes[$k]))
                        <tr>
                            <td style="width:42%"><span class="dot" style="background:{{ $color[$k] ?? '#94a3b8' }}"></span>{{ $r['label'] }}</td>
                            <td><div class="bar"><i style="width:{{ round($porRes[$k] / $total * 100) }}%"></i></div></td>
                            <td class="r">{{ $porRes[$k] }}</td>
                        </tr>
                    @endif
                @endforeach
            </table>
        </td>
        <td style="width:42%;vertical-align:top">
            <h2>Quién paga</h2>
            <table class="res">
                @foreach($cov as $k => $l)
                    @if(!empty($porCov[$k]))
                        <tr><td><span class="cov {{ $k }}">{{ $l }}</span></td><td class="r">{{ $porCov[$k] }}</td></tr>
                    @endif
                @endforeach
            </table>
            @if($docs->count())
                <h2>Documentos</h2>
                <table class="res">
                    @foreach($docs as $d)
                        <tr><td>{{ $kinds[$d->kind]['label'] }} {{ $d->number }}@if($d->coverage) <span class="cov {{ $d->coverage }}">{{ $cov[$d->coverage] }}</span>@endif</td>
                            <td class="r" style="width:80px">{{ $d->total !== null ? $eur($d->total) : '' }}</td></tr>
                    @endforeach
                </table>
            @endif
        </td>
    </tr></table>

    <h2>Detalle por dispositivo</h2>
    <table class="dev">
        <thead><tr><th style="width:14%">Código</th><th style="width:22%">Producto</th><th style="width:19%">Tienda</th><th style="width:16%">Resultado</th><th>Detalle</th></tr></thead>
        <tbody>
            @foreach($items as $i)
                @php
                    $det = [];
                    if ($i->resolution === 'bateria' && $i->battery_changed) $det[] = 'Pilas cambiadas';
                    if ($i->resolution === 'danada' && $i->broken_cases) $det[] = collect($i->broken_cases)->map(fn ($k) => $broken[$k] ?? $k)->implode(', ');
                    if ($i->zkong_result) $det[] = 'Fabricante: ' . ($zk[$i->zkong_result] ?? $i->zkong_result) . ($i->zkong_result_note ? ' — ' . $i->zkong_result_note : '');
                    if ($i->resolution_note) $det[] = $i->resolution_note;
                @endphp
                <tr>
                    <td><span class="mono">{{ $i->code }}</span>@if($i->kind === 'ap')<div class="det">Antena</div>@endif</td>
                    <td>{{ $i->aeme_ref ?: ($i->server_model ?: '—') }}@if($i->product_name)<div class="det">{{ $i->product_name }}</div>@endif</td>
                    <td>{{ $i->store_name ?: '—' }}</td>
                    <td><span class="dot" style="background:{{ $color[$i->resolution] ?? '#94a3b8' }}"></span>{{ $res[$i->resolution]['label'] ?? $i->resolution }}
                        @if($i->coverage)<div style="margin-top:3px"><span class="cov {{ $i->coverage }}">{{ $cov[$i->coverage] }}</span></div>@endif</td>
                    <td>{{ implode(' · ', $det) ?: '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if($photos)
        <h2 style="page-break-before: always">Fotos</h2>
        @foreach($items as $i)
            @if(!empty($photos[$i->id]))
                <div class="foto">
                    <div class="h"><span class="mono">{{ $i->code }}</span> · {{ $res[$i->resolution]['label'] ?? $i->resolution }}</div>
                    <table><tr>
                        @foreach($photos[$i->id] as [$tipo, $uri])
                            <td><img src="{{ $uri }}"><small>{{ $fotoTipo[$tipo] ?? $tipo }}</small></td>
                        @endforeach
                        @for($k = count($photos[$i->id]); $k < 3; $k++)<td></td>@endfor
                    </tr></table>
                </div>
            @endif
        @endforeach
    @endif

    <div class="foot">{{ $company['name'] ?? 'AEME' }} · Informe de resolución {{ $number }} · Garantía {{ $w->code }}</div>
</body>
</html>
