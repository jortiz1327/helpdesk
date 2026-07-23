<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Services\TicketService;
use Illuminate\Support\Facades\DB;

/** Helpers de contactos/mensajes. Portado de upsert_contact + store_message. */
class ChatService
{
    /** Busca o crea un contacto por su wa_id; devuelve su id. */
    public static function upsertContact(string $waId, ?string $name = null): int
    {
        $c = Contact::where('wa_id', $waId)->first();
        if ($c) {
            if ($name && $name !== $c->name) {
                $c->name = $name;
                $c->save();
            }
            return (int) $c->id;
        }
        return (int) Contact::create(['wa_id' => $waId, 'name' => $name])->id;
    }

    /**
     * Busca o crea un contacto por su EMAIL (canal correo). Devuelve su id.
     * Los contactos de correo no tienen wa_id (teléfono); se identifican por email.
     */
    public static function upsertContactByEmail(string $email, ?string $name = null): int
    {
        $email = mb_strtolower(trim($email));
        $c = Contact::where('email', $email)->first();
        if ($c) {
            if ($name && $name !== $c->name) {
                $c->name = $name;
                $c->save();
            }
            return (int) $c->id;
        }
        return (int) Contact::create(['email' => $email, 'name' => $name ?: $email])->id;
    }

    /** Inserta un mensaje y actualiza el resumen del contacto. Devuelve el id. */
    public static function storeMessage(int $contactId, string $waId, string $direction, string $type, string $body, array $opts = []): int
    {
        $payload = $opts['payload'] ?? null;
        if (is_array($payload)) {
            $payload = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        $attrs = [
            'contact_id'       => $contactId,
            'ticket_id'        => $opts['ticket_id'] ?? null,
            'wa_id'            => $waId,
            'direction'        => $direction,
            'channel'          => $opts['channel'] ?? 'whatsapp',
            'author_user_id'   => $opts['author_user_id'] ?? null,
            'type'             => $type,
            'body'             => $body,
            'is_html'          => (bool) ($opts['is_html'] ?? false),
            'is_internal_note' => (bool) ($opts['is_internal_note'] ?? false),
            'media_url'        => $opts['media_url'] ?? null,
            'media_mime'       => $opts['media_mime'] ?? null,
            // Copias del correo (lista separada por comas). Del Cco solo sabemos el
            // de lo que enviamos nosotros: el de un correo entrante no llega nunca.
            'cc'               => $opts['cc'] ?? null,
            'bcc'              => $opts['bcc'] ?? null,
            'wamid'            => $opts['wamid'] ?? null,
            'status'           => $opts['status'] ?? ($direction === 'in' ? 'received' : 'sent'),
            'sent_by'          => $opts['sent_by'] ?? null,
            'payload'          => $payload,
        ];
        // Fecha real del mensaje (p.ej. la del correo entrante). Si no se pasa, la BD
        // usa la hora actual (útil para WhatsApp/web, que llegan en el momento).
        if (!empty($opts['created_at'])) $attrs['created_at'] = $opts['created_at'];

        $msg = Message::create($attrs);

        // El resumen del contacto (last_message/last_time/unread) es el de la vista
        // «Chat en vivo» de Campañas, que es SOLO WhatsApp. Los mensajes de correo/web
        // (soporte) NO lo tocan: su actividad vive en el ticket, no en esa bandeja.
        if (($opts['channel'] ?? 'whatsapp') === 'whatsapp') {
            $preview = $opts['preview'] ?? ($body !== '' ? $body : '[' . $type . ']');
            DB::table('contacts')->where('id', $contactId)->update([
                'last_message' => mb_substr($preview, 0, 120),
                'last_time'    => DB::raw('NOW()'),
                'unread'       => $direction === 'in' ? DB::raw('unread + 1') : DB::raw('unread'),
            ]);
        }

        // Mantener ordenada la bandeja de tickets
        if (!empty($opts['ticket_id'])) {
            $cambios = ['last_message_at' => DB::raw('NOW()')];

            /*
             * QUIÉN HABLÓ EL ÚLTIMO, guardado en el ticket. Antes se calculaba con una
             * subconsulta por cada ticket cada vez que se pintaba la bandeja; así se
             * escribe una vez, al llegar el mensaje.
             * Las notas internas NO cuentan: no son una respuesta al cliente.
             */
            if (empty($opts['is_internal_note'])) $cambios['last_direction'] = $direction;

            DB::table('tickets')->where('id', $opts['ticket_id'])->update($cambios);

            /*
             * Auto-paso: cuando un AGENTE (autor humano) responde por primera vez a un
             * ticket nuevo, pasa a «En progreso». Solo si hay autor → el bot (autor null)
             * no lo dispara, ni las notas internas. Hoy es no-op (aún no hay respuestas
             * humanas); se activa solo al montar el envío real.
             */
            if ($direction === 'out' && !empty($opts['author_user_id']) && empty($opts['is_internal_note'])) {
                app(TicketService::class)->markFirstResponse((int) $opts['ticket_id'], (int) $opts['author_user_id']);
            }
        }

        return (int) $msg->id;
    }
}
