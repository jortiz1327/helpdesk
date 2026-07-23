<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #1e2833; margin: 0; }
        .head { border-bottom: 2px solid #2563eb; padding-bottom: 10px; margin-bottom: 16px; }
        .head .code { color: #2563eb; font-weight: bold; font-size: 13px; letter-spacing: 0.5px; }
        .head h1 { font-size: 17px; margin: 3px 0 8px; }
        .meta { font-size: 10.5px; color: #5a6b78; line-height: 1.7; }
        .meta b { color: #1e2833; }
        .pill { display: inline-block; padding: 1px 7px; border-radius: 4px; font-size: 9.5px; font-weight: bold; }
        .pill.st { background: #e7f0ff; color: #2563eb; }

        .msg { margin: 0 0 9px; padding: 8px 11px; border-radius: 6px; page-break-inside: avoid; }
        .msg.in  { background: #f1f3f5; }
        .msg.out { background: #e7f0ff; }
        .msg.note { background: #fff4e0; border-left: 3px solid #f59e0b; }
        .who { font-weight: bold; font-size: 10.5px; margin-bottom: 4px; color: #33414d; }
        .who .tag { display: inline-block; background: #f59e0b; color: #3a2703; padding: 0 5px; border-radius: 3px; font-size: 9px; margin-right: 4px; }
        .who .time { color: #93a3ac; font-weight: normal; }
        .body { font-size: 12px; line-height: 1.5; }
        .body img { max-width: 100%; }
        .body img.sz-50 { width: 50%; }
        .body img.sz-25 { width: 25%; }
        .empty { color: #93a3ac; font-style: italic; }
        .foot { margin-top: 18px; padding-top: 8px; border-top: 1px solid #dde2e6; font-size: 9px; color: #93a3ac; text-align: center; }
    </style>
</head>
<body>
    <div class="head">
        <div class="code">{{ $t->code }}</div>
        <h1>{{ $t->subject ?: 'Ticket' }}</h1>
        <div class="meta">
            <b>Cliente:</b> {{ $t->contact_name ?: ($t->contact_wa ? '+'.$t->contact_wa : ($t->contact_email ?: '—')) }}
            &nbsp;·&nbsp; <b>Estado:</b> <span class="pill st">{{ $statuses[$t->status] ?? $t->status }}</span>
            &nbsp;·&nbsp; <b>Prioridad:</b> {{ $priorities[$t->priority] ?? $t->priority }}<br>
            <b>Categoría:</b> {{ $t->category_name ?: 'Sin categoría' }}
            &nbsp;·&nbsp; <b>Agente:</b> {{ $t->agent_name ?: 'Sin asignar' }}<br>
            <b>Creado:</b> {{ $t->created_at }}
            @if($t->resolved_at) &nbsp;·&nbsp; <b>Resuelto:</b> {{ $t->resolved_at }} @endif
        </div>
    </div>

    @forelse($messages as $m)
        @php $cls = $m->is_internal_note ? 'note' : ($m->direction === 'out' ? 'out' : 'in'); @endphp
        <div class="msg {{ $cls }}">
            <div class="who">
                @if($m->is_internal_note)
                    <span class="tag">NOTA INTERNA</span> {{ $m->author_name ?: '—' }}
                @elseif($m->direction === 'out')
                    {{ $m->author_name ?: 'Automático' }}
                @else
                    {{ $t->contact_name ?: 'Cliente' }}
                @endif
                <span class="time"> · {{ $m->created_at }}</span>
            </div>
            <div class="body">{!! $bodies[$m->id] ?: '<span class="empty">[sin texto]</span>' !!}</div>
        </div>
    @empty
        <p class="empty">Este ticket no tiene mensajes.</p>
    @endforelse

    <div class="foot">Generado desde HelpDesk · {{ now()->format('d/m/Y H:i') }}</div>
</body>
</html>
