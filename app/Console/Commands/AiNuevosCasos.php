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
        {--semilla=1 : Semilla del reparto aleatorio (para repetir el mismo lote)}';

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
        $lote = (string) ($this->option('lote') ?: 'nuevos-' . now()->format('Ymd'));
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
            $caso = $this->caso($t, $lote);
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
