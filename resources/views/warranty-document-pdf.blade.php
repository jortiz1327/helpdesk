@php
    $eur = fn ($n) => number_format((float) $n, 2, ',', '.') . ' €';
    $num = fn ($n) => rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ',');
    $cobra = $doc->charges();
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        @page { margin: 34px 38px 46px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1e2833; margin: 0; }
        table { border-collapse: collapse; width: 100%; }
        .top td { vertical-align: top; }
        .empresa b { font-size: 15px; }
        .empresa div { color: #5a6b78; font-size: 9.5px; line-height: 1.6; }
        .tipo { text-align: right; }
        .tipo h1 { margin: 0; font-size: 20px; color: #2563eb; letter-spacing: 0.5px; }
        .tipo .n { font-size: 12px; font-weight: bold; margin-top: 2px; }
        .tipo .f { color: #5a6b78; font-size: 9.5px; margin-top: 4px; line-height: 1.6; }
        .cov { display: inline-block; margin-top: 6px; padding: 2px 8px; border-radius: 4px; font-size: 9px; font-weight: bold; }
        .cov.garantia { background: #dcfce7; color: #166534; }
        .cov.cliente { background: #ffedd5; color: #9a3412; }
        .cov.pilas { background: #fef3c7; color: #92400e; }
        .datos { margin: 18px 0 14px; }
        .datos td { width: 50%; padding: 8px 11px; background: #f4f6f8; border: 3px solid #fff; vertical-align: top; line-height: 1.6; }
        .datos small { color: #5a6b78; text-transform: uppercase; font-size: 8px; letter-spacing: 0.5px; display: block; }
        .lin th { background: #1e2833; color: #fff; font-size: 8.5px; text-transform: uppercase; letter-spacing: 0.4px; padding: 6px 7px; text-align: left; }
        .lin td { padding: 7px; border-bottom: 1px solid #e3e7eb; vertical-align: top; }
        .lin .r { text-align: right; white-space: nowrap; }
        .lin .ref { font-weight: bold; white-space: nowrap; }
        .codes { color: #6b7a86; font-size: 8px; margin-top: 3px; font-family: 'DejaVu Sans Mono', monospace; line-height: 1.5; }
        .sin { color: #166534; font-weight: bold; }
        .tot { width: 46%; margin: 12px 0 0 auto; }
        .tot td { padding: 4px 7px; }
        .tot .r { text-align: right; }
        .tot .final td { border-top: 2px solid #1e2833; font-size: 13px; font-weight: bold; padding-top: 7px; }
        .nota { margin-top: 20px; padding: 9px 11px; border-left: 3px solid #2563eb; background: #f4f6f8; font-size: 9.5px; line-height: 1.6; white-space: pre-line; }
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
            <h1>{{ mb_strtoupper($kindLabel) }}</h1>
            <div class="n">{{ $doc->number }}</div>
            <div class="f">
                Fecha: {{ $doc->created_at->format('d/m/Y') }}
                @if($doc->kind === 'presupuesto' && $validity)<br>Válido hasta: {{ $doc->created_at->copy()->addDays($validity)->format('d/m/Y') }}@endif
                @if($doc->status === 'borrador')<br><b style="color:#b45309">BORRADOR</b>@elseif($doc->status === 'anulado')<br><b style="color:#dc2626">ANULADO</b>@endif
            </div>
            @if($coverageLabel)<span class="cov {{ $doc->coverage }}">{{ $doc->coverage === 'garantia' ? 'Cubierto por la garantía' : $coverageLabel }}</span>@endif
        </td>
    </tr></table>

    <table class="datos"><tr>
        <td><small>Cliente</small><b>{{ $w->client_name ?: '—' }}</b>@if($w->project_name)<br>{{ $w->project_name }}@endif</td>
        <td><small>Expediente de garantía</small><b>{{ $w->code }}</b><br>Recibida el {{ $w->received_at?->format('d/m/Y') }}</td>
    </tr></table>

    <table class="lin">
        <thead><tr><th style="width:17%">Referencia</th><th>Descripción</th><th class="r" style="width:9%">Cant.</th><th class="r" style="width:14%">Precio</th><th class="r" style="width:15%">Importe</th></tr></thead>
        <tbody>
            @forelse($lines as $l)
                <tr>
                    <td class="ref">{{ $l['ref'] ?: '—' }}</td>
                    <td>{{ $l['description'] }}
                        @if($l['codes'])<div class="codes">{{ implode(' · ', $l['codes']) }}</div>@endif
                    </td>
                    <td class="r">{{ $num($l['qty']) }}</td>
                    <td class="r">{{ $eur($l['price']) }}</td>
                    <td class="r">@if($cobra){{ $eur($l['qty'] * $l['price']) }}@else<span class="sin">Sin cargo</span>@endif</td>
                </tr>
            @empty
                <tr><td colspan="5" style="color:#93a3ac;text-align:center;padding:16px">Sin líneas</td></tr>
            @endforelse
        </tbody>
    </table>

    <table class="tot">
        @if($cobra)
            <tr><td>Base imponible</td><td class="r">{{ $eur($t['base']) }}</td></tr>
            <tr><td>IVA {{ $num($doc->vat) }} %</td><td class="r">{{ $eur($t['vat_amount']) }}</td></tr>
            <tr class="final"><td>Total</td><td class="r">{{ $eur($t['total']) }}</td></tr>
        @else
            <tr class="final"><td>Total</td><td class="r">0,00 €</td></tr>
            <tr><td colspan="2" style="color:#5a6b78;font-size:9px;text-align:right">Cubierto por la garantía: sin cargo para el cliente</td></tr>
        @endif
    </table>

    @if($doc->note)<div class="nota">{{ $doc->note }}</div>@endif

    <div class="foot">{{ $company['name'] ?? 'AEME' }} · {{ $kindLabel }} {{ $doc->number }} · Garantía {{ $w->code }}</div>
</body>
</html>
