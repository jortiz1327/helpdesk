<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Señal de que algo se movió en un ticket. Se emite por websocket (Reverb) a
 * todos los usuarios de soporte conectados.
 *
 * DECISIÓN IMPORTANTE: por el socket NO viajan datos del ticket, solo la SEÑAL
 * (qué pasó y en qué ticket). El cliente, al recibirla, vuelve a pedir los datos
 * por la API normal, que ya comprueba permisos.
 *
 * Así, aunque alguien se colara en el canal, no se llevaría ni el contenido de
 * una conversación ni los datos de un cliente. El socket avisa; la API decide
 * qué puede ver cada uno. Es una capa menos donde equivocarse.
 */
class TicketActivity implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $action,       // created | message | status | assigned
        public int $ticketId,
        public string $code = '',    // referencia visible (TK-2607-0001), para el aviso
        public string $subject = '', // asunto, para el aviso
        public ?int $assignedTo = null,
    ) {}

    /** Canal PRIVADO: hay que autenticarse para escucharlo (ver routes/channels.php). */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('tickets')];
    }

    public function broadcastAs(): string
    {
        return 'ticket.activity';
    }
}
