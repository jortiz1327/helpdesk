<?php

namespace App\Console\Commands;

use App\Services\Ai\Scrub;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Añade un LOTE de casos nuevos al conjunto de pruebas (storage/app/ai-eval): tickets reales ya resueltos que NO estén
 * ya en candidatos.json, con los datos personales tapados igual que la memoria de la IA. Quedan SIN REVISAR en /ia/casos
 * (filtro «Sin revisar»); `ai:prerrellenar` les propone decisión y `ai:evaluar --lote=...` mide solo ese lote.
 *
 * No llama a ningún proveedor de IA ni gasta tokens. Hace copia de candidatos.json y marcas.json antes de tocarlos.
 */
class AiNuevosCasos extends Command
{
    protected $signature = 'ai:nuevos-casos
        {--cantidad=30 : Casos a añadir}
        {--lote= : Nombre del lote (por defecto nuevos-AAAAMMDD)}
        {--semilla=1 : Semilla del reparto aleatorio (para repetir el mismo lote)}
        {--seguimiento : Casos de SEGUIMIENTO: la conversación se corta en el 2.º o 3.º mensaje del cliente (tras una respuesta de una persona)}';

    protected $description = 'Añade al conjunto de pruebas un lote de tickets reales nuevos, anonimizados y sin revisar';

    public function handle(): int
    {
        $dir = (string) config('ai.eval_dir');
        if (! is_file("$dir/candidatos.json") || ! is_file("$dir/marcas.json")) {
            $this->error('Faltan storage/app/ai-eval/candidatos.json y marcas.json.');
            return self::FAILURE;
        }
        $cand = json_decode((string) file_get_contents("$dir/candidatos.json"), true);
        $marcas = json_decode((string) file_get_contents("$dir/marcas.json"), true);
        // Se compara por CÓDIGO: los ids de los casos viejos vienen de producción y no coinciden con los de la base local.
        $ya = array_flip(array_column($cand, 'code'));
        $idsUsados = array_flip(array_map('strval', array_column($cand, 'id')));
        $seg = (bool) $this->option('seguimiento');
        $lote = (string) ($this->option('lote') ?: ($seg ? 'seg-' : 'nuevos-') . now()->format('Ymd'));
        $n = max(1, (int) $this->option('cantidad'));

        $tickets = DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
            ->whereIn('t.status', ['resuelto', 'cerrado'])->whereNull('t.merged_into_id')
            ->whereIn('t.channel', ['email', 'whatsapp'])
            ->where('t.opened_at', '>=', '2025-09-01')
            ->get(['t.id', 't.code', 't.subject', 't.channel', 't.category_id', 't.opened_at', 'c.name as contact_name']);
        $tickets = $tickets->reject(fn ($t) => isset($ya[$t->code]) || isset($idsUsados[(string) $t->id]))->values()->all();
        mt_srand((int) $this->option('semilla'));   // mismo reparto cada vez que se repite con la misma semilla
        shuffle($tickets);

        $porCanal = ['email' => [], 'whatsapp' => []];
        $meta = (int) ceil($n / 2);
        foreach ($tickets as $t) {
            if (count($porCanal['email']) >= $meta && count($porCanal['whatsapp']) >= $n - $meta) {
                break;
            }
            if (count($porCanal[$t->channel]) >= ($t->channel === 'email' ? $meta : $n - $meta)) {
                continue;
            }
            $caso = $seg ? $this->casoSeguimiento($t, $lote) : $this->caso($t, $lote);
            if ($caso) {
                $porCanal[$t->channel][] = $caso;
            }
        }
        $nuevos = array_merge($porCanal['email'], $porCanal['whatsapp']);
        if (! $nuevos) {
            $this->warn('No se ha encontrado ningún ticket que sirva.');
            return self::SUCCESS;
        }

        $stamp = now()->format('Ymd-His');
        copy("$dir/candidatos.json", "$dir/candidatos.bak-$stamp.json");
        copy("$dir/marcas.json", "$dir/marcas.bak-$stamp.json");
        foreach ($nuevos as $c) {
            $cand[] = $c;
            $marcas[$c['id']] = ['decision' => 'responder', 'comentario' => '', 'revisado' => false];
        }
        file_put_contents("$dir/candidatos.json", json_encode($cand, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
        file_put_contents("$dir/marcas.json", json_encode($marcas, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);

        $this->info(count($nuevos) . " casos nuevos en el lote «{$lote}» (". count($porCanal['email']) . ' correo, ' . count($porCanal['whatsapp']) . ' WhatsApp). Ahora hay ' . count($cand) . ' en total.');
        $this->line('Siguiente: php artisan ai:prerrellenar (propuestas) y revisarlos en /ia/casos con el filtro «Sin revisar».');
        return self::SUCCESS;
    }

    /**
     * Pasa los mensajes de un ticket a TURNOS alternados cliente / persona (los seguidos del mismo lado se unen).
     * Función pura, sin base de datos.
     *
     * @param  array<int, array{role:string, text:string}>  $mensajes  ya con el texto limpio, en orden
     * @return array<int, array{role:string, text:string}>
     */
    public static function turnos(array $mensajes): array
    {
        $out = [];
        foreach ($mensajes as $m) {
            if (trim($m['text']) === '') {
                continue;
            }
            if ($out && $out[count($out) - 1]['role'] === $m['role']) {
                $out[count($out) - 1]['text'] .= "\n\n" . $m['text'];
            } else {
                $out[] = ['role' => $m['role'], 'text' => $m['text']];
            }
        }
        return $out;
    }

    /**
     * Dónde cortar una conversación para probar un SEGUIMIENTO: el índice del 2.º mensaje del cliente (o del 3.º, si $tercero),
     * siempre que lo siguiente sea una respuesta de una persona, que será lo que se compare. null si no hay.
     *
     * @param  array<int, array{role:string, text:string}>  $turnos
     */
    public static function puntoDeCorte(array $turnos, bool $tercero = false): ?int
    {
        $clientes = [];
        foreach ($turnos as $i => $t) {
            if ($t['role'] === 'user') {
                $clientes[] = $i;
            }
        }
        $orden = $tercero ? [2, 1] : [1, 2];   // posición (desde 0) del mensaje del cliente que se prueba
        foreach ($orden as $k) {
            $i = $clientes[$k] ?? null;
            if ($i !== null && isset($turnos[$i + 1]) && $turnos[$i + 1]['role'] === 'assistant' && mb_strlen(trim($turnos[$i]['text'])) >= 20) {
                return $i;
            }
        }
        return null;
    }

    /** El caso de seguimiento de un ticket (o null si no sirve). */
    private function casoSeguimiento(object $t, string $lote): ?array
    {
        $msgs = DB::table('messages')->where('ticket_id', $t->id)->where('is_internal_note', 0)->whereNotNull('body')
            ->orderBy('id')->get(['direction', 'body', 'author_user_id', 'type']);
        if ($msgs->count() < 4 || $msgs->count() > 40) {
            return null;
        }
        $nombre = (string) ($t->contact_name ?? '');
        $humano = fn ($m) => $m->direction === 'out' && ($t->channel === 'whatsapp'
            ? $m->type === 'text' && ! str_contains((string) $m->body, 'horario de oficina') && mb_strlen(trim(strip_tags((string) $m->body))) >= 25
            : (bool) $m->author_user_id);
        $lista = [];
        foreach ($msgs as $m) {
            if ($m->direction === 'out' && ! $humano($m)) {
                continue;   // aviso automático: no es un turno
            }
            $lista[] = ['role' => $m->direction === 'in' ? 'user' : 'assistant', 'text' => trim(Scrub::tapar(Scrub::plano((string) $m->body), $nombre))];
        }
        $turnos = self::turnos($lista);
        if (! isset($turnos[0]) || $turnos[0]['role'] !== 'user') {
            return null;
        }
        $corte = self::puntoDeCorte($turnos, mt_rand(0, 1) === 1);
        if ($corte === null) {
            return null;
        }
        $respuesta = trim($turnos[$corte + 1]['text']);
        if (mb_strlen($respuesta) < 40) {
            return null;
        }
        $conv = array_map(fn ($x) => ['role' => $x['role'], 'text' => mb_substr($x['text'], 0, 1200)], array_slice($turnos, 0, $corte + 1));
        $historial = implode("\n\n", array_map(fn ($x) => ($x['role'] === 'user' ? 'CLIENTE: ' : 'EQUIPO: ') . $x['text'], array_slice($conv, 0, -1)));

        return [
            'id' => (int) $t->id, 'code' => $t->code, 'canal' => $t->channel, 'categoria' => (int) ($t->category_id ?? 0),
            'abierto' => (string) $t->opened_at, 'asunto' => mb_substr(Scrub::tapar((string) $t->subject, $nombre), 0, 255),
            'pregunta' => $conv[count($conv) - 1]['text'], 'historial' => $historial, 'conversacion' => $conv,
            'respuesta_humana' => mb_substr($respuesta, 0, 2500), 'mensajes' => $msgs->count(), 'decision_propuesta' => null,
            'lote' => $lote, 'seguimiento' => true,
        ];
    }

    /** El caso de un ticket (o null si no sirve: sin petición clara, sin respuesta de una persona, demasiado largo…). */
    private function caso(object $t, string $lote): ?array
    {
        $msgs = DB::table('messages')->where('ticket_id', $t->id)->where('is_internal_note', 0)->whereNotNull('body')
            ->orderBy('id')->get(['direction', 'body', 'author_user_id', 'type']);
        if ($msgs->count() < 2 || $msgs->count() > 40) {
            return null;
        }
        // Quién responde como persona: en correo, un agente con autor; en WhatsApp las respuestas se reflejan sin autor, y solo
        // se descartan el aviso automático de horario y los saludos cortos.
        $humano = fn ($m) => $m->direction === 'out' && ($t->channel === 'whatsapp'
            ? $m->type === 'text' && ! str_contains((string) $m->body, 'horario de oficina') && mb_strlen(trim(strip_tags((string) $m->body))) >= 25
            : (bool) $m->author_user_id);
        // Lo que escribe el cliente ANTES de la primera respuesta (en WhatsApp suele llegar en varios mensajes seguidos).
        $preg = [];
        foreach ($msgs as $m) {
            if ($humano($m)) {   // el aviso automático de WhatsApp no corta lo que escribe el cliente
                break;
            }
            if ($m->direction === 'out') {
                continue;
            }
            $x = trim(Scrub::plano((string) $m->body));
            if ($x !== '') {
                $preg[] = $x;
            }
        }
        $resp = $msgs->filter($humano)
            ->map(fn ($m) => trim(Scrub::plano((string) $m->body)))->filter()->values();
        $nombre = (string) ($t->contact_name ?? '');
        $q = trim(Scrub::tapar(implode("\n", $preg), $nombre));
        $r = trim(Scrub::tapar($resp->implode("\n\n"), $nombre));
        if (mb_strlen($q) < 40 || mb_strlen($r) < 60) {   // sin petición clara o con respuestas de una línea no enseñan nada
            return null;
        }

        return [
            'id' => (int) $t->id, 'code' => $t->code, 'canal' => $t->channel, 'categoria' => (int) ($t->category_id ?? 0),
            'abierto' => (string) $t->opened_at, 'asunto' => mb_substr(Scrub::tapar((string) $t->subject, $nombre), 0, 255),
            'pregunta' => mb_substr($q, 0, 1500), 'respuesta_humana' => mb_substr($r, 0, 2500),
            'mensajes' => $msgs->count(), 'decision_propuesta' => null, 'lote' => $lote,
        ];
    }
}
