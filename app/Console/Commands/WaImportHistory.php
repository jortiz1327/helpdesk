<?php

namespace App\Console\Commands;

use App\Services\SoporteWhatsApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Convierte en TICKETS lo capturado del número de soporte (Coexistence) en
 * `wa_raw_events`: el historial del móvil (`history`), lo que entra en directo
 * (`messages`) y lo que se contesta desde el móvil (`smb_message_echoes`).
 *
 * Pensado para trabajar el DISEÑO de los chats en LOCAL: por defecto se niega a
 * correr en producción. Agrupa cada chat en conversaciones cortando por silencio
 * (--gap días); la última de cada cliente queda abierta si tuvo actividad en los
 * últimos --open días, el resto cerradas. Reutiliza el contacto por teléfono (sin
 * modificarlo) o lo crea. Repetible: --reset borra antes lo que importó él mismo.
 *
 *   php artisan wa:import-history --json="C:\…\wa_soporte_01_historial.json" --reset
 */
class WaImportHistory extends Command
{
    protected $signature = 'wa:import-history
        {--json= : Cargar antes un JSON exportado de wa_raw_events (si la tabla local está vacía)}
        {--gap=7 : Días de silencio que abren una conversación nueva}
        {--open=7 : La última conversación queda abierta si tuvo actividad en estos días}
        {--reset : Borrar antes los tickets importados por este comando}
        {--dry-run : Solo contar, sin escribir}
        {--produccion : Permitir ejecutarlo en producción (NO para el diseño)}';

    protected $description = 'WhatsApp soporte: convierte el historial y los mensajes capturados en tickets (local)';

    private const SOURCE = 'wa-history';

    public function handle(): int
    {
        if (app()->environment('production') && !$this->option('produccion')) {
            $this->error('Esto es para LOCAL. En producción solo con --produccion (y a sabiendas).');
            return self::FAILURE;
        }

        if ($json = $this->option('json')) {
            if (!$this->cargarJson($json)) return self::FAILURE;
        }

        $bizNumber = (string) (DB::table('whatsapp_numbers')->where('funcion', 'soporte')->value('display_number') ?? '');
        $bizNumber = preg_replace('/\D+/', '', $bizNumber) ?: '34608923001';

        [$chats, $nombres] = $this->leerChats($bizNumber);
        $gap  = max(1, (int) $this->option('gap')) * 86400;
        $open = max(0, (int) $this->option('open')) * 86400;

        // Partir cada chat en conversaciones por silencio.
        $convs = [];
        foreach ($chats as $waId => $msgs) {
            usort($msgs, fn ($a, $b) => $a['ts'] <=> $b['ts']);
            $cur = [];
            foreach ($msgs as $m) {
                if ($cur && $m['ts'] - end($cur)['ts'] > $gap) { $convs[] = [$waId, $cur]; $cur = []; }
                $cur[] = $m;
            }
            if ($cur) $convs[] = [$waId, $cur];
        }
        $nMsgs = array_sum(array_map(fn ($c) => count($c[1]), $convs));
        $this->info(sprintf('%d chats · %d conversaciones (silencio > %d días) · %d mensajes', count($chats), count($convs), $gap / 86400, $nMsgs));
        if ($this->option('dry-run')) return self::SUCCESS;

        if ($this->option('reset')) $this->reset();

        $ultimaDe = [];   // índice de la última conversación de cada chat
        foreach ($convs as $i => [$waId]) $ultimaDe[$waId] = $i;

        $prioridad = (string) (DB::table('ticket_priorities')->where('is_default', 1)->value('key')
            ?? DB::table('ticket_priorities')->orderBy('position')->value('key') ?? 'media');
        $contadores = [];
        $creados = ['tickets' => 0, 'mensajes' => 0, 'contactos' => 0, 'abiertos' => 0];
        $bar = $this->output->createProgressBar(count($convs));

        foreach ($convs as $i => [$waId, $msgs]) {
            DB::transaction(function () use ($i, $waId, $msgs, $ultimaDe, $open, $prioridad, $nombres, &$contadores, &$creados) {
                $contactId = $this->contacto($waId, $nombres[$waId] ?? null, $creados);
                $ini = $msgs[0]['ts']; $fin = end($msgs)['ts']; $last = end($msgs);
                $abierto = $ultimaDe[$waId] === $i && (time() - $fin) <= $open;

                $primeraIn  = collect($msgs)->firstWhere('dir', 'in');
                $primeraOut = $primeraIn ? collect($msgs)->first(fn ($m) => $m['dir'] === 'out' && $m['ts'] >= $primeraIn['ts']) : null;
                $asunto = $primeraIn && $primeraIn['body'] ? $primeraIn['body'] : 'Conversación de WhatsApp';
                $asunto = mb_strimwidth(trim(preg_replace('/\s+/u', ' ', $asunto)), 0, 90, '…');

                $mes = date('ym', $ini);
                $contadores[$mes] ??= (int) substr((string) DB::table('tickets')->where('code', 'like', "TK-$mes-%")->max('code'), -4);
                $code = sprintf('TK-%s-%04d', $mes, ++$contadores[$mes]);

                $f = fn ($ts) => date('Y-m-d H:i:s', $ts);
                $tid = DB::table('tickets')->insertGetId([
                    'code' => $code, 'subject' => $asunto, 'category_id' => null,
                    'status' => $abierto ? 'abierto' : 'cerrado', 'priority' => $prioridad,
                    'channel' => 'whatsapp', 'source' => self::SOURCE, 'contact_id' => $contactId,
                    'opened_at' => $f($ini), 'first_response_at' => $primeraOut ? $f($primeraOut['ts']) : null,
                    'resolved_at' => $abierto ? null : $f($fin), 'closed_at' => $abierto ? null : $f($fin),   // ended_at es columna calculada
                    'last_message_at' => $f($fin), 'conversation_since' => $f($ini), 'last_direction' => $last['dir'],
                    'created_at' => $f($ini), 'updated_at' => $f($fin),
                ]);
                DB::table('ticket_events')->insert(['ticket_id' => $tid, 'type' => 'created', 'to_value' => 'whatsapp', 'created_at' => $f($ini)]);

                foreach (array_chunk($msgs, 500) as $lote) {
                    DB::table('messages')->insert(array_map(fn ($m) => [
                        'contact_id' => $contactId, 'ticket_id' => $tid, 'wa_id' => $waId,
                        'direction' => $m['dir'], 'channel' => 'whatsapp', 'funcion' => 'soporte',
                        'type' => $m['tipo'], 'body' => $m['body'], 'is_html' => 0, 'is_internal_note' => 0,
                        'media_url' => $m['media'], 'media_mime' => $m['mime'],
                        'wamid' => $m['id'], 'status' => $m['dir'] === 'out' ? 'sent' : 'received',
                        // _via: lo nuestro salió del móvil (WhatsApp Business), no de un agente ni del bot.
                        'payload' => json_encode($m['dir'] === 'out' ? $m['raw'] + ['_via' => 'movil'] : $m['raw'], JSON_UNESCAPED_UNICODE),
                        'created_at' => $f($m['ts']),
                    ], $lote));
                }
                $creados['tickets']++; $creados['mensajes'] += count($msgs); if ($abierto) $creados['abiertos']++;
            });
            $bar->advance();
        }
        $bar->finish(); $this->newLine();
        // Lo de directo que ya va en la importación no tiene que volver a pasar por wa:process-raw.
        DB::table('wa_raw_events')->whereIn('field', ['messages', 'smb_message_echoes'])
            ->whereNull('processed_at')->update(['processed_at' => now()]);
        $this->info(sprintf('Creados: %d tickets (%d abiertos) · %d mensajes · %d contactos nuevos',
            $creados['tickets'], $creados['abiertos'], $creados['mensajes'], $creados['contactos']));
        return self::SUCCESS;
    }

    /**
     * Mensajes por chat (wa_id del cliente), sin duplicados por id, desde los tres
     * tipos de evento. Devuelve [chats, nombres de perfil].
     */
    private function leerChats(string $biz): array
    {
        $chats = []; $vistos = []; $nombres = [];
        $add = function (string $waId, array $m, string $dir) use (&$chats, &$vistos) {
            $id = (string) ($m['id'] ?? '');
            if ($waId === '' || $id === '' || isset($vistos[$id])) return;
            // Mismo mapeo que lo que entra en directo. El historial trae fotos/audios como
            // `media_placeholder` (sin archivo): el hilo pinta «Archivo no disponible».
            [$tipo, $body, $media] = SoporteWhatsApp::contenido($m);
            if ($tipo === null) return;   // ediciones, errores… no son mensajes
            $vistos[$id] = true;
            $chats[$waId][] = ['id' => $id, 'ts' => (int) $m['timestamp'], 'dir' => $dir, 'tipo' => $tipo, 'body' => $body,
                               'media' => $media['id'] ?? null, 'mime' => $media['mime_type'] ?? null, 'raw' => $m];
        };

        foreach (DB::table('wa_raw_events')->orderBy('id')->cursor() as $e) {
            $v = json_decode($e->payload, true) ?: [];
            if ($e->field === 'history') {
                foreach ($v['history'] ?? [] as $h) foreach ($h['threads'] ?? [] as $t) {
                    $waId = (string) ($t['context']['wa_id'] ?? $t['id'] ?? '');
                    foreach ($t['messages'] ?? [] as $m) {
                        $add($waId, $m, preg_replace('/\D+/', '', (string) ($m['from'] ?? '')) === $biz ? 'out' : 'in');
                    }
                }
            } elseif ($e->field === 'messages') {
                foreach ($v['contacts'] ?? [] as $c) {
                    if (!empty($c['profile']['name'])) $nombres[(string) $c['wa_id']] = $c['profile']['name'];
                }
                foreach ($v['messages'] ?? [] as $m) $add((string) ($m['from'] ?? ''), $m, 'in');
            } elseif ($e->field === 'smb_message_echoes') {
                foreach ($v['message_echoes'] ?? [] as $m) $add((string) ($m['to'] ?? ''), $m, 'out');
            }
        }
        return [$chats, $nombres];
    }


    /** Contacto por teléfono: se reutiliza tal cual (sin modificarlo) o se crea. */
    private function contacto(string $waId, ?string $nombre, array &$creados): int
    {
        if ($id = DB::table('contacts')->where('wa_id', $waId)->value('id')) return (int) $id;
        $creados['contactos']++;
        return (int) DB::table('contacts')->insertGetId([
            'wa_id' => $waId, 'name' => $nombre ?: null, 'created_at' => now(),
        ]);
    }

    private function reset(): void
    {
        $ids = DB::table('tickets')->where('source', self::SOURCE)->pluck('id');
        foreach ($ids->chunk(500) as $c) {
            DB::table('messages')->whereIn('ticket_id', $c)->delete();
            DB::table('ticket_events')->whereIn('ticket_id', $c)->delete();
            DB::table('tickets')->whereIn('id', $c)->delete();
        }
        $this->warn("Reset: borrados {$ids->count()} tickets importados antes (los contactos se conservan).");
    }

    private function cargarJson(string $path): bool
    {
        if (DB::table('wa_raw_events')->exists()) {
            $this->line('wa_raw_events ya tiene datos: no se recarga el JSON.');
            return true;
        }
        if (!is_file($path)) { $this->error("No existe: $path"); return false; }
        $rows = json_decode(file_get_contents($path), true);
        if (!is_array($rows)) { $this->error('JSON no válido'); return false; }
        foreach (array_chunk($rows, 50) as $lote) {
            DB::table('wa_raw_events')->insert(array_map(fn ($r) => [
                'field' => $r['field'], 'waba_id' => $r['waba_id'] ?? null, 'phone_number_id' => $r['phone_number_id'] ?? null,
                'payload' => json_encode($r['payload'], JSON_UNESCAPED_UNICODE), 'created_at' => $r['created_at'] ?? now(),
            ], $lote));
        }
        $this->info('Cargados ' . count($rows) . ' eventos en wa_raw_events (local).');
        return true;
    }
}
