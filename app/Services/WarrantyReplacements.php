<?php

namespace App\Services;

use App\Models\User;
use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Models\ZkongServer;
use App\Services\Zkong\ZkongDevices;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Sustituciones: las etiquetas que no se devuelven (soluciones de Configuración →
 * Finanzas) se cambian por nuevas, las cubramos nosotros o el cliente. En un hotel cada
 * etiqueta está vinculada a una posición (el artículo en ZKONG, p. ej. SC-02): se apunta
 * antigua → posición → nueva y, si la categoría lo pide, se ofrece crear un ticket con
 * las posiciones a vincular, a nombre del correo del cliente.
 */
class WarrantyReplacements
{
    public const DEFAULTS = [
        'categories'         => ['Hoteles'],
        'ticket_category_id' => null,
        'subject'            => 'Nuevas etiquetas para {proyecto} ({codigo})',
        'intro'              => "Por la garantía {codigo} se envían {n} etiquetas nuevas que sustituyen a las que no se devuelven. Hay que vincular cada una a su posición:",
    ];

    public static function settings(): array
    {
        return WarrantyDocuments::ajuste('replacements', self::DEFAULTS);
    }

    /** Soluciones con las que el dispositivo se cambia por uno nuevo. */
    public static function solutions(): array
    {
        return WarrantyDocuments::ajuste('finance', WarrantyDocuments::FINANCE_DEFAULTS)['replaced'];
    }

    /** Los dispositivos de la garantía que se sustituyen. */
    public static function items(Warranty $w): Collection
    {
        return WarrantyItem::where('warranty_id', $w->id)->whereIn('resolution', self::solutions())->orderBy('id')->get();
    }

    /** Si al apuntar las nuevas hay que ofrecer el ticket de posiciones (por la categoría). */
    public static function asksTicket(Warranty $w): bool
    {
        $c = mb_strtolower(trim((string) $w->category));
        return $c !== '' && collect(self::settings()['categories'])->contains(fn ($x) => mb_strtolower(trim($x)) === $c);
    }

    /**
     * La posición de cada etiqueta a sustituir, preguntando a ZKONG por su artículo
     * vinculado. Solo rellena las que no la tienen (lo que apuntó el agente manda).
     */
    public static function lookup(Warranty $w, ?int $userId): array
    {
        $items = self::items($w)->where('kind', 'esl')->filter(fn ($i) => ! $i->position);
        if ($items->isEmpty()) return ['found' => 0, 'missing' => 0, 'failed' => []];
        $dev = app(ZkongDevices::class);
        $res = $dev->labels($items->pluck('code')->all(), $userId);
        $porServidor = [];
        foreach ($res['labels'] as $code => $l) {
            $v = $l['live'] ?? null;
            if ($v && ! empty($v['server_id']) && ! empty($v['storeId'])) {
                $porServidor[$v['server_id']][] = ['storeId' => $v['storeId'], 'barCode' => (string) $code];
            }
        }
        $art = [];
        $fallidos = $res['failed'];
        foreach ($porServidor as $sid => $lista) {
            $s = ZkongServer::find($sid);
            if (! $s) continue;
            try {
                $art += $dev->items($s, $lista);
            } catch (Throwable $e) {
                report($e);
                $fallidos[] = $s->name;
            }
        }
        $n = 0;
        foreach ($items as $i) {
            $a = $art[$i->code] ?? null;
            if ($a && ($a['itemBarCode'] || $a['itemTitle'])) {
                $i->update(['position' => mb_substr((string) ($a['itemBarCode'] ?: $a['itemTitle']), 0, 80),
                    'position_title' => $a['itemBarCode'] && $a['itemTitle'] ? mb_substr((string) $a['itemTitle'], 0, 191) : null]);
                $n++;
            }
        }
        return ['found' => $n, 'missing' => $items->count() - $n, 'failed' => array_values(array_unique($fallidos))];
    }

    private static function vars(Warranty $w, int $n, string $agente): array
    {
        return ['{codigo}' => $w->code, '{cliente}' => (string) $w->client_name, '{proyecto}' => (string) ($w->project_name ?: $w->client_name),
            '{n}' => (string) $n, '{agente}' => $agente];
    }

    /** Lo que llevaría el ticket: asunto y cuerpo (HTML con la tabla de posiciones). */
    public static function ticketContent(Warranty $w, Collection $items, string $agente): array
    {
        $cfg = self::settings();
        $vars = self::vars($w, $items->count(), $agente);
        $filas = $items->map(fn ($i) => '<tr><td>' . e($i->position ?: '—') . ($i->position_title ? ' <small>' . e($i->position_title) . '</small>' : '')
            . '</td><td>' . e($i->code) . '</td><td><b>' . e($i->replaced_by) . '</b></td></tr>')->implode('');
        $html = '<p>' . nl2br(e(strtr($cfg['intro'], $vars))) . '</p>'
            . '<table><thead><tr><th>Posición</th><th>Etiqueta antigua</th><th>Etiqueta nueva</th></tr></thead><tbody>' . $filas . '</tbody></table>';
        $texto = strtr($cfg['intro'], $vars) . "\n" . $items->map(fn ($i) => ($i->position ?: '—') . ": {$i->code} → {$i->replaced_by}")->implode("\n");
        return ['subject' => mb_substr(strtr($cfg['subject'], $vars), 0, 200), 'html' => $html, 'text' => $texto];
    }

    /**
     * Crea el ticket de posiciones a nombre del correo del cliente (sin acuse: no se le
     * escribe nada). Una vez por garantía; queda enlazado y en su historial.
     */
    public static function createTicket(Warranty $w, string $email, User $me): array|string
    {
        $items = self::items($w)->filter(fn ($i) => $i->replaced_by);
        if ($items->isEmpty()) return 'Apunta primero las etiquetas nuevas';
        if ($w->positions_ticket_id && ($code = DB::table('tickets')->where('id', $w->positions_ticket_id)->value('code'))) {
            return "Ya está creado el ticket de posiciones {$code}";
        }
        $email = mb_strtolower(trim($email));
        $c = self::ticketContent($w, $items, $me->name);
        $cat = self::settings()['ticket_category_id'];
        $cat = $cat && DB::table('ticket_categories')->where('id', $cat)->where('active', true)->exists() ? (int) $cat : null;

        $contactId = DB::table('contacts')->where('email', $email)->value('id');
        if (! $contactId) {
            try {
                $contactId = DB::table('contacts')->insertGetId(['name' => (string) ($w->project_name ?: $w->client_name ?: $email), 'email' => $email]);
            } catch (\Illuminate\Database\QueryException $e) {
                $contactId = DB::table('contacts')->where('email', $email)->value('id') ?: throw $e;
            }
        }

        $tid = DB::transaction(function () use ($w, $contactId, $c, $cat, $me, $email) {
            $tid = app(TicketService::class)->create([
                'contact_id' => $contactId, 'channel' => 'email', 'source' => 'manual', 'subject' => $c['subject'],
                'category_id' => $cat, 'priority' => 'media', 'user_id' => (int) $me->id, 'body' => $c['text'], 'email' => $email,
            ], notify: false);
            DB::table('messages')->insert(['contact_id' => $contactId, 'ticket_id' => $tid, 'direction' => 'in', 'channel' => 'email',
                'type' => 'text', 'body' => HtmlSanitizer::clean($c['html']), 'is_html' => true, 'status' => 'received']);
            DB::table('tickets')->where('id', $tid)->update(['last_direction' => 'in']);
            $w->update(['positions_ticket_id' => $tid]);
            return $tid;
        });
        $code = DB::table('tickets')->where('id', $tid)->value('code');
        WarrantyDocuments::evento($w->id, $me->id, "Ticket de posiciones {$code} creado para {$email} ({$items->count()} etiquetas nuevas)");
        return ['id' => $tid, 'code' => $code];
    }
}
