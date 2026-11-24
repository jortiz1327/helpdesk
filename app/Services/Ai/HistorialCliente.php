<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;

/**
 * Historial del cliente como contexto de la sugerencia: los últimos tickets anteriores del MISMO contacto (su correo es único) y de otros usuarios de su MISMO hotel (sede recordada y vigente), resumidos en una línea cada uno.
 *
 * Reglas:
 *  · Solo cuando el correo del cliente está VERIFICADO (nunca se cuenta lo de un hotel a quien no se ha comprobado).
 *  · Sin llamada extra a la IA: el «resumen» es lo que pidió y lo que contestó una persona, recortado y con los datos personales tapados.
 *  · Nada de notas internas ni mensajes automáticos; no se incluyen nombres de otros contactos.
 *  · Los tickets de otros usuarios del hotel se marcan como tales (no son «lo que ya le dijimos a él»).
 */
class HistorialCliente
{
    public const MAX_TICKETS = 3;
    public const MESES = 12;
    private const LARGO = 170;

    /**
     * @return string  Bloque para el prompt, o '' si no hay nada que contar.
     */
    public static function para(int $contactId, ?int $excluirTicket): string
    {
        $c = DB::table('contacts')->where('id', $contactId)->first(['id', 'name']);
        if (! $c) {
            return '';
        }
        $propios = [$contactId];

        $otros = [];
        $sede = ContactoHotel::vigente($contactId);
        if ($sede) {
            $otros = DB::table('contacts')->where('sede_id', $sede->id)->whereNotIn('id', $propios)->limit(200)->pluck('id')->map(fn ($i) => (int) $i)->all();
        }

        $desde = now()->subMonths(self::MESES);
        $q = DB::table('tickets as t')
            ->whereIn('t.contact_id', array_merge($propios, $otros))
            ->whereNull('t.merged_into_id')
            ->where('t.created_at', '>=', $desde)
            ->orderByDesc('t.id')
            ->limit(self::MAX_TICKETS);
        if ($excluirTicket) {
            $q->where('t.id', '!=', $excluirTicket);
        }
        $tickets = $q->get(['t.id', 't.subject', 't.status', 't.created_at', 't.contact_id']);
        if ($tickets->isEmpty()) {
            return '';
        }

        $nombre = trim((string) $c->name);
        $lineas = [];
        foreach ($tickets as $t) {
            $propio = in_array((int) $t->contact_id, $propios, true);
            $lineas[] = '- ' . date('d/m/Y', strtotime((string) $t->created_at)) . ' · ' . ($propio ? 'del propio cliente' : 'otro usuario de su hotel')
                . ' · «' . self::limpiar((string) $t->subject, $nombre, 90) . '» · ' . self::estado((string) $t->status)
                . self::cuerpo($t->id, $nombre);
        }
        return "\nHISTORIAL DEL CLIENTE (últimos tickets anteriores suyos o de su hotel, ya verificado). Es SOLO contexto para entender mejor el caso:\n"
            . "- No lo cites, no digas que lo has leído y no afirmes que algo quedó resuelto salvo que aquí conste. Si el problema es el mismo de antes, no repitas lo que ya se le explicó: pasa al siguiente paso según las reglas.\n"
            . "- Los tickets de otros usuarios de su hotel NO son lo que se le dijo a él.\n"
            . implode("\n", $lineas) . "\n";
    }

    private static function estado(string $s): string
    {
        return in_array($s, ['resuelto', 'cerrado'], true) ? 'resuelto' : 'abierto';
    }

    /** « · pidió: «…» · el equipo contestó: «…»» (lo que había en el primer mensaje del cliente y en la última respuesta de una persona). */
    private static function cuerpo(int $ticketId, string $nombre): string
    {
        $msgs = DB::table('messages')->where('ticket_id', $ticketId)->where('is_internal_note', 0)->whereNotNull('body')
            ->orderBy('id')->get(['direction', 'body', 'is_html', 'author_user_id']);
        $in = $msgs->firstWhere('direction', 'in');
        $out = $msgs->filter(fn ($m) => $m->direction === 'out' && $m->author_user_id)->last();
        $s = '';
        if ($in) {
            $s .= ' · pidió: «' . self::limpiar((string) $in->body, $nombre, self::LARGO, (bool) $in->is_html) . '»';
        }
        if ($out) {
            $s .= ' · el equipo contestó: «' . self::limpiar((string) $out->body, $nombre, self::LARGO, (bool) $out->is_html) . '»';
        }
        return $s;
    }

    private static function limpiar(string $texto, string $nombre, int $largo, bool $html = false): string
    {
        $t = $html ? Scrub::plano($texto) : $texto;
        $t = Scrub::tapar(Scrub::firma($t), $nombre);
        $t = trim((string) preg_replace('/\s+/u', ' ', $t));
        return mb_strlen($t) > $largo ? rtrim(mb_substr($t, 0, $largo)) . '…' : $t;
    }
}
