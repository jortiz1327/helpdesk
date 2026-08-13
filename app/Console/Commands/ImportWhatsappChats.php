<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Importa el histórico de WhatsApp (CSV exportado) como TICKETS CERRADOS.
 *
 * Cada chat individual se parte en «sesiones» por huecos de tiempo (--gap días):
 * una racha de mensajes seguidos = un ticket, con sus fechas ORIGINALES. Los
 * contactos se crean/reutilizan por su teléfono. Marca los tickets con
 * `source` para poder revertir (--fresh los borra antes de reimportar).
 *
 * Uso:
 *   php artisan wa:import "ruta.csv" --dry-run          (solo cuenta)
 *   php artisan wa:import "ruta.csv" --fresh             (importa de verdad)
 */
class ImportWhatsappChats extends Command
{
    protected $signature = 'wa:import
        {file : Ruta del CSV}
        {--gap=3 : Días de silencio que separan una conversación de la siguiente}
        {--channel=whatsapp : Canal de los tickets}
        {--source=import-wa : Marca en tickets.source (para poder revertir)}
        {--limit=0 : Máximo de tickets a crear (0 = sin límite)}
        {--per-contact : Un solo ticket por contacto (todo el chat junto) en vez de uno por sesión}
        {--funcion=soporte : Marca en los mensajes: soporte | campanas}
        {--no-tickets : Solo chat (contactos + mensajes), sin crear tickets (para Campañas)}
        {--dry-run : No inserta nada, solo informa de lo que haría}
        {--fresh : Borra antes lo ya importado (tickets del source, o mensajes de la función si --no-tickets)}';

    protected $description = 'Importa el histórico de WhatsApp (CSV) como tickets cerrados o como chat de campañas';

    private array $codeSeq = [];
    private string $funcion = 'soporte';

    public function handle(): int
    {
        $path = $this->argument('file');
        if (!is_file($path)) { $this->error("No existe: $path"); return 1; }

        $gap     = max(0, (int) $this->option('gap')) * 86400;
        $channel = (string) $this->option('channel');
        $source  = (string) $this->option('source');
        $limit   = (int) $this->option('limit');
        $dry     = (bool) $this->option('dry-run');
        $noTickets = (bool) $this->option('no-tickets');
        $this->funcion = ((string) $this->option('funcion')) === 'campanas' ? 'campanas' : 'soporte';

        if ($this->option('fresh') && !$dry) {
            if ($noTickets) {
                $n = DB::table('messages')->where('funcion', $this->funcion)->whereNull('ticket_id')->count();
                if ($n) {
                    $this->warn("Borrando $n mensajes de chat previos (funcion={$this->funcion})…");
                    DB::table('messages')->where('funcion', $this->funcion)->whereNull('ticket_id')->delete();
                }
            } else {
                $n = DB::table('tickets')->where('source', $source)->count();
                if ($n) {
                    $this->warn("Borrando $n tickets previos (source=$source) y sus mensajes…");
                    DB::table('tickets')->where('source', $source)->delete();   // messages caen por FK cascade
                }
            }
        }

        $perContact = (bool) $this->option('per-contact');

        $this->info('Leyendo y agrupando el CSV…');
        $chats = $this->agrupar($path);
        $this->info('Chats individuales: ' . count($chats) . ($perContact ? ' (un ticket por contacto)' : ''));

        $tickets = 0; $mensajes = 0; $contactos = 0; $saltados = 0;
        $bar = $this->output->createProgressBar(count($chats));

        foreach ($chats as $wa => $data) {
            // CAMPAÑAS: chat sin tickets. Todo el chat del contacto entra como mensajes.
            if ($noTickets) {
                if (!$data['msgs']) { $bar->advance(); continue; }
                if ($dry) { $contactos++; $mensajes += count($data['msgs']); $bar->advance(); continue; }
                $contactId = $this->contacto($wa, $data['name']);
                $contactos++;
                $mensajes += $this->insertarChat($wa, $contactId, $data['msgs'], $channel);
                $ult = end($data['msgs']);
                DB::table('contacts')->where('id', $contactId)->update([
                    'last_message' => mb_substr($ult[2], 0, 500),
                    'last_time'    => date('Y-m-d H:i:s', $ult[0]),
                ]);
                $bar->advance();
                continue;
            }

            // Un ticket por contacto: todo el chat en una sola "sesión".
            $sesiones = $perContact ? [$data['msgs']] : $this->sesionizar($data['msgs'], $gap);
            $contactId = null;   // se resuelve al primer ticket real del chat

            foreach ($sesiones as $ses) {
                $primer = $this->primerClienteConTexto($ses);
                if ($primer === null) { $saltados++; continue; }        // sin texto de cliente
                if ($limit && $tickets >= $limit) break 2;

                if (!$dry && $contactId === null) {
                    $contactId = $this->contacto($wa, $data['name']);
                    $contactos++;
                }

                // Dónde empieza la conversación más reciente (para el separador).
                $convSince = $perContact ? $this->ultimaRachaInicio($ses, $gap) : null;

                // El ASUNTO refleja la conversación más reciente (no el mensaje de hace años).
                $asunto = $primer;
                if ($perContact && $convSince) {
                    $ts = strtotime($convSince);
                    $ultima = array_values(array_filter($ses, fn ($m) => $m[0] >= $ts));
                    $asunto = $this->primerClienteConTexto($ultima) ?: $primer;
                }

                [$ins, $ticketId] = $this->crearTicket($wa, $contactId, $ses, $asunto, $channel, $source, $dry, $convSince);
                $tickets++; $mensajes += $ins;

                if (!$dry && $contactId) {
                    $ult = end($ses);
                    DB::table('contacts')->where('id', $contactId)->update([
                        'last_message' => mb_substr($ult[2], 0, 500),
                        'last_time'    => date('Y-m-d H:i:s', $ult[0]),
                    ]);
                }
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        $filas = [];
        if (!$noTickets) {
            $filas[] = ['Tickets ' . ($dry ? 'que se crearían' : 'creados'), number_format($tickets)];
        } else {
            $filas[] = ['Conversaciones de chat (funcion=' . $this->funcion . ')', number_format($contactos)];
        }
        $filas[] = ['Mensajes ' . ($dry ? 'que se insertarían' : 'insertados'), number_format($mensajes)];
        $filas[] = ['Contactos ' . ($dry ? '(no se tocan en dry-run)' : 'dados de alta/reutilizados'), number_format($contactos)];
        if (!$noTickets) $filas[] = ['Sesiones saltadas (sin texto de cliente)', number_format($saltados)];
        $this->table(['', 'Resultado'], $filas);
        if ($dry) $this->warn('DRY-RUN: no se ha insertado nada. Quita --dry-run para importar.');

        return 0;
    }

    /** Agrupa el CSV por chat individual → mensajes + nombre candidato. */
    private function agrupar(string $path): array
    {
        $fh = fopen($path, 'r');
        fgetcsv($fh); // cabecera
        $chats = [];

        while (($r = fgetcsv($fh)) !== false) {
            if (count($r) < 6) continue;
            [$chat, $tipo, $fecha, $dir, $rem, $msg] = $r;
            if ($tipo !== 'individual') continue;

            $wa = preg_replace('/\D+/', '', (string) $chat);   // teléfono → solo dígitos
            if (strlen($wa) < 7 || strlen($wa) > 20) continue;  // descarta nombres/ruido

            $body = trim((string) $msg);
            if ($body === '' || str_contains($body, '[mensaje de sistema]')) continue;

            $ts  = strtotime($fecha);
            $out = $dir === 'Yo';

            if (!isset($chats[$wa])) $chats[$wa] = ['msgs' => [], 'name' => null];
            $chats[$wa]['msgs'][] = [$ts, $out, $body];

            // Nombre del contacto: un remitente entrante que no sea un número.
            if (!$out && $chats[$wa]['name'] === null) {
                $rem = trim((string) $rem);
                if ($rem !== '' && $rem !== 'Yo' && !preg_match('/^\+?[\d ]+$/', $rem)) {
                    $chats[$wa]['name'] = mb_substr($rem, 0, 160);
                }
            }
        }
        fclose($fh);

        foreach ($chats as &$c) usort($c['msgs'], fn ($a, $b) => $a[0] <=> $b[0]);
        return $chats;
    }

    /** Parte los mensajes de un chat en sesiones por hueco de tiempo. */
    private function sesionizar(array $msgs, int $gap): array
    {
        $ses = []; $cur = []; $prev = null;
        foreach ($msgs as $m) {
            if ($prev !== null && ($m[0] - $prev) > $gap) { $ses[] = $cur; $cur = []; }
            $cur[] = $m; $prev = $m[0];
        }
        if ($cur) $ses[] = $cur;
        return $ses;
    }

    /**
     * Asunto del ticket: el primer mensaje del cliente CON ENJUNDIA (no un saludo
     * suelto), porque casi siempre abren con «Hola/Buenas». Si no encuentra ninguno
     * sustancial, coge el más largo de los primeros; si no hay texto de cliente,
     * devuelve null (y esa sesión no genera ticket).
     */
    private function primerClienteConTexto(array $ses): ?string
    {
        $cli = [];
        foreach ($ses as $m) {
            if (!$m[1] && $m[2] !== '' && !preg_match('/^\[/', $m[2])) {
                $t = trim(preg_replace('/\s+/', ' ', $m[2]));
                if ($t !== '') $cli[] = $t;
            }
        }
        if (!$cli) return null;

        // Saludo/relleno puro (es/cat/en): no sirve como asunto.
        $saludo = '/^(hola|buenas|buenos d[ií]as|buenas tardes|buenas noches|hi|hey|ola|bon dia|bones|bon nadal|gr[àa]cies|gracias|ok|vale|perfecto|adi[oó]s|hasta luego)[\s!¡.,:;)\-]*$/iu';

        foreach (array_slice($cli, 0, 6) as $c) {
            // Longitud sin emojis, para no contar adornos.
            $sinEmoji = trim(preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}]/u', '', $c));
            if (mb_strlen($sinEmoji) >= 15 && !preg_match($saludo, $c)) return $c;
        }

        // Nada sustancial: el más largo de los primeros seis.
        $best = $cli[0];
        foreach (array_slice($cli, 0, 6) as $c) if (mb_strlen($c) > mb_strlen($best)) $best = $c;
        return $best;
    }

    private function contacto(string $wa, ?string $name): int
    {
        $c = DB::table('contacts')->where('wa_id', $wa)->first();
        if ($c) {
            if ($name && !$c->name) DB::table('contacts')->where('id', $c->id)->update(['name' => $name]);
            return (int) $c->id;
        }
        return (int) DB::table('contacts')->insertGetId([
            'wa_id'      => $wa,
            'name'       => $name,
            'note'       => '[importado del histórico de WhatsApp]',
            'created_at' => now(),
        ]);
    }

    /** Inicio (Y-m-d H:i:s) de la última racha de mensajes, separada por $gap. */
    private function ultimaRachaInicio(array $ses, int $gap): string
    {
        $start = $ses[0][0]; $prev = null;
        foreach ($ses as $m) {
            if ($prev !== null && ($m[0] - $prev) > $gap) $start = $m[0];
            $prev = $m[0];
        }
        return date('Y-m-d H:i:s', $start);
    }

    /** Crea un ticket cerrado con sus mensajes. Devuelve [nº mensajes, ticketId]. */
    private function crearTicket(string $wa, ?int $contactId, array $ses, string $primer, string $channel, string $source, bool $dry, ?string $convSince = null): array
    {
        $iniTs = $ses[0][0];
        $finTs = end($ses)[0];
        $primeraResp = null;
        foreach ($ses as $m) { if ($m[1]) { $primeraResp = $m[0]; break; } }

        $ini = date('Y-m-d H:i:s', $iniTs);
        $fin = date('Y-m-d H:i:s', $finTs);

        if ($dry) return [count($ses), 0];

        $ticketId = DB::table('tickets')->insertGetId([
            'code'              => $this->nextCode(date('ym', $iniTs)),
            'subject'           => mb_substr($primer, 0, 200),
            'status'            => 'cerrado',
            'priority'          => 'media',
            'channel'           => $channel,
            'source'            => $source,
            'contact_id'        => $contactId,
            'opened_at'         => $ini,
            // Histórico: los tiempos de atención y resolución no tienen sentido (los
            // chats abarcan años), así que se dejan a CERO igualándolos a la apertura.
            'first_response_at' => $ini,
            'resolved_at'       => $ini,
            'closed_at'         => $fin,
            'last_message_at'   => $fin,
            'conversation_since' => $convSince,
            'created_at'        => $ini,
            'updated_at'        => $fin,
        ]);

        $filas = [];
        foreach ($ses as $m) {
            $filas[] = [
                'ticket_id'        => $ticketId,
                'contact_id'       => $contactId,
                'wa_id'            => $wa,
                'direction'        => $m[1] ? 'out' : 'in',
                'channel'          => $channel,
                'funcion'          => $this->funcion,
                'type'             => $this->tipo($m[2]),
                'body'             => $m[2],
                'is_internal_note' => 0,
                'status'           => 'sent',
                'created_at'       => date('Y-m-d H:i:s', $m[0]),
            ];
        }
        foreach (array_chunk($filas, 500) as $lote) DB::table('messages')->insert($lote);

        return [count($ses), $ticketId];
    }

    /** Inserta el chat de un contacto SIN ticket (para Campañas). Devuelve nº de mensajes. */
    private function insertarChat(string $wa, int $contactId, array $msgs, string $channel): int
    {
        $filas = [];
        foreach ($msgs as $m) {
            $filas[] = [
                'ticket_id'        => null,
                'contact_id'       => $contactId,
                'wa_id'            => $wa,
                'direction'        => $m[1] ? 'out' : 'in',
                'channel'          => $channel,
                'funcion'          => $this->funcion,
                'type'             => $this->tipo($m[2]),
                'body'             => $m[2],
                'is_internal_note' => 0,
                'status'           => 'sent',
                'created_at'       => date('Y-m-d H:i:s', $m[0]),
            ];
        }
        foreach (array_chunk($filas, 500) as $lote) DB::table('messages')->insert($lote);
        return count($filas);
    }

    private function tipo(string $body): string
    {
        if (preg_match('/^\[(imagen|foto)/iu', $body)) return 'image';
        if (preg_match('/^\[(video)/iu', $body)) return 'video';
        if (preg_match('/^\[(audio|nota de voz)/iu', $body)) return 'audio';
        if (preg_match('/^\[(documento|archivo|pdf)/iu', $body)) return 'document';
        return 'text';
    }

    /** TK-AAMM-NNNN con la fecha real del ticket; secuencia por mes sin colisionar. */
    private function nextCode(string $ym): string
    {
        if (!isset($this->codeSeq[$ym])) {
            $prefix = 'TK-' . $ym . '-';
            $last = DB::table('tickets')->where('code', 'like', $prefix . '%')->orderByDesc('code')->value('code');
            $this->codeSeq[$ym] = $last ? (int) substr($last, -4) : 0;
        }
        $this->codeSeq[$ym]++;
        return 'TK-' . $ym . '-' . str_pad((string) $this->codeSeq[$ym], 4, '0', STR_PAD_LEFT);
    }
}
