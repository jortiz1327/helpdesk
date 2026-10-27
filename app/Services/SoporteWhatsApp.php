<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp de SOPORTE (el 01, Coexistence) → tickets. Fase 2.
 *
 * Lo que escribe el cliente y lo que se le contesta DESDE EL MÓVIL (smb_message_echoes)
 * entra en el ticket de WhatsApp de ese cliente, con el mismo criterio que la
 * importación del historial (WaImportHistory):
 *  · Ticket vivo (nuevo/abierto/planificado) → se añade ahí.
 *  · Resuelto/cerrado con actividad hace MENOS de 7 días → es la misma conversación:
 *    el mensaje del cliente lo REABRE (siempre: cerrar es cosa del agente), y lo
 *    nuestro desde el móvil se añade sin cambiar el estado.
 *  · Si no, conversación NUEVA → ticket nuevo (nace sin prioridad, como todos).
 *
 * Nada de Campañas: ni bot, ni CONSENTIMIENTO (soporte no envía publicidad: es una
 * herramienta para organizarnos), ni BAJA/ALTA, ni el resumen del «Chat en vivo».
 * Solo actúa con el ajuste `wa_soporte_tickets` = 1; si no, el webhook se limita a
 * guardar el evento crudo (wa_raw_events), como en la fase 1.
 */
class SoporteWhatsApp
{
    /** Silencio tras el que un mensaje abre conversación (ticket) nueva. Igual que la importación. */
    public const DIAS_CONVERSACION = 7;

    public function __construct(protected TicketService $tickets) {}

    public static function activo(): bool
    {
        return (string) Setting::get('wa_soporte_tickets', '0') === '1';
    }

    /**
     * Procesa un evento crudo (el `value` de un change del webhook). Devuelve cuántos
     * mensajes nuevos guardó. Los campos que no son mensajes (history, account_update,
     * smb_app_state_sync…) se quedan solo en wa_raw_events.
     */
    public function procesar(string $field, array $value): int
    {
        $n = 0;
        if ($field === 'messages') {
            $nombres = [];
            foreach ($value['contacts'] ?? [] as $c) {
                if (!empty($c['wa_id'])) $nombres[(string) $c['wa_id']] = $c['profile']['name'] ?? null;
            }
            foreach ($value['messages'] ?? [] as $m) {
                $from = (string) ($m['from'] ?? '');
                if ($from !== '' && $this->guardar($from, $m, 'in', $nombres[$from] ?? null)) $n++;
            }
        } elseif ($field === 'smb_message_echoes') {
            foreach ($value['message_echoes'] ?? [] as $m) {
                $to = (string) ($m['to'] ?? '');
                if ($to !== '' && $this->guardar($to, $m, 'out', null)) $n++;
            }
        }
        return $n;
    }

    /** Guarda un mensaje en su ticket. false si ya estaba (Meta reintenta) o no se muestra. */
    protected function guardar(string $waId, array $m, string $dir, ?string $nombre): bool
    {
        $wamid = (string) ($m['id'] ?? '');
        if ($wamid === '' || DB::table('messages')->where('wamid', $wamid)->exists()) return false;

        [$tipo, $body, $media] = self::contenido($m);
        if ($tipo === null) return false;   // reacciones, ediciones, errores…

        $contactId = $this->contacto($waId, $nombre);
        $cuando = isset($m['timestamp'])
            ? Carbon::createFromTimestamp((int) $m['timestamp'])->setTimezone(config('app.timezone'))
            : now();

        [$ticketId, $nuevo] = $this->ticketPara($contactId, $dir, $body ?? '', $tipo);

        $raw = $dir === 'out' ? $m + ['_via' => 'movil'] : $m;   // lo nuestro: salió del móvil
        ChatService::storeMessage($contactId, $waId, $dir, $tipo, (string) ($body ?? ''), [
            'ticket_id'  => $ticketId,
            'channel'    => 'whatsapp',
            'funcion'    => 'soporte',
            'wamid'      => $wamid,
            'status'     => $dir === 'in' ? 'received' : 'sent',
            'media_url'  => $media['id'] ?? null,
            'media_mime' => $media['mime_type'] ?? null,
            'payload'    => $raw,
            'created_at' => $cuando,
        ]);

        if ($dir === 'out') {
            // Contestar desde el móvil también es atender: cuenta como primera respuesta.
            $this->tickets->markFirstResponse($ticketId);
        } elseif (!$nuevo) {
            // Escribe el cliente en un ticket que ya existía y que NO tiene responsable (los
            // del historial importado nacieron así): se reparte ahora, con la misma lógica
            // que un ticket nuevo (turno de guardia). Si ya tiene responsable, no se toca.
            app(ShiftService::class)->asignarSiProcede($ticketId);
            try { app(NotifyService::class)->ticket('ticket_reply', $ticketId); }
            catch (\Throwable $e) { Log::warning('WhatsApp soporte: aviso de respuesta falló', ['ticket' => $ticketId, 'error' => $e->getMessage()]); }
        }
        // Aviso a los agentes solo cuando escribe el CLIENTE en un ticket que ya existía (el
        // nuevo ya avisó con «created»). Lo nuestro desde el móvil solo refresca pantallas.
        $this->tickets->broadcast($dir === 'out' ? 'reply' : ($nuevo ? 'updated' : 'message'), $ticketId);
        return true;
    }

    /**
     * Ticket donde va el mensaje: [id, ¿recién creado?]. Ver el criterio en la cabecera.
     */
    protected function ticketPara(int $contactId, string $dir, string $body, string $tipo): array
    {
        $t = DB::table('tickets')->where('contact_id', $contactId)->where('channel', 'whatsapp')
            ->whereNull('merged_into_id')->orderByDesc('id')
            ->first(['id', 'status', 'last_message_at']);

        if ($t && in_array($t->status, TicketService::OPEN_STATUSES, true)) return [(int) $t->id, false];

        $reciente = $t && $t->last_message_at
            && Carbon::parse($t->last_message_at)->gt(now()->subDays(self::DIAS_CONVERSACION));
        if ($reciente) {
            // El cliente vuelve a escribir: la pelota está en nuestro tejado → se reabre,
            // SIEMPRE (también un «gracias»: puede ir seguido de otra consulta; lo cierra el agente).
            if ($dir === 'in') $this->tickets->setStatus((int) $t->id, 'abierto');
            return [(int) $t->id, false];
        }

        $asunto = trim(preg_replace('/\s+/u', ' ', $body));
        $id = $this->tickets->create([
            'contact_id'         => $contactId,
            'channel'            => 'whatsapp',
            'source'             => 'whatsapp',
            'subject'            => $asunto !== '' ? mb_strimwidth($asunto, 0, 90, '…')
                                   : ($dir === 'out' ? 'Conversación iniciada desde el móvil' : 'Conversación de WhatsApp'),
            'body'               => $body,
            // Sin conversation_since: aquí el ticket YA es la conversación (el separador
            // «Conversación más reciente» saldría tras el primer mensaje, que Meta fecha
            // unos segundos antes de crear el ticket).
        ]);
        return [$id, true];
    }

    /**
     * [tipo, texto, media] del mensaje de Meta; tipo null si no se muestra. Mismo
     * mapeo que el webhook de Campañas, más los tipos de Coexistence.
     */
    public static function contenido(array $m): array
    {
        $tipo = (string) ($m['type'] ?? '');
        return match ($tipo) {
            'text'        => ['text', (string) ($m['text']['body'] ?? ''), null],
            'image', 'video', 'audio', 'document', 'sticker'
                          => [$tipo, ($c = trim((string) ($m[$tipo]['caption'] ?? ''))) !== '' ? $c : null, $m[$tipo] ?? null],
            'location'    => ['location', '📍 ' . ($m['location']['latitude'] ?? '') . ', ' . ($m['location']['longitude'] ?? '')
                                . (($n = $m['location']['name'] ?? '') ? " · $n" : ''), null],
            'contacts'    => ['text', '👤 Contacto compartido: ' . collect($m['contacts'] ?? [])
                                ->map(fn ($c) => trim(($c['name']['formatted_name'] ?? '') . ' ' . ($c['phones'][0]['phone'] ?? '')))
                                ->filter()->implode(', '), null],
            'button'      => ['text', (string) ($m['button']['text'] ?? ''), null],
            'interactive' => ['text', (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? '(respuesta interactiva)'), null],
            'media_placeholder' => ['unavailable', null, null],
            'reaction', 'edit', 'errors', 'unsupported', 'revoke', 'system', 'ephemeral' => [null, null, null],
            default       => ['text', "($tipo)", null],
        };
    }

    /**
     * Contacto por teléfono. Si ya existe se reutiliza y solo se le pone nombre si no
     * tenía (el nombre de perfil de WhatsApp no pisa el que haya puesto un agente).
     */
    protected function contacto(string $waId, ?string $nombre): int
    {
        $c = DB::table('contacts')->where('wa_id', $waId)->first(['id', 'name']);
        if ($c) {
            if ($nombre && !$c->name) DB::table('contacts')->where('id', $c->id)->update(['name' => $nombre]);
            return (int) $c->id;
        }
        return (int) DB::table('contacts')->insertGetId(['wa_id' => $waId, 'name' => $nombre ?: null, 'created_at' => now()]);
    }
}
