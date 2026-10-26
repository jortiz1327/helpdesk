<?php

namespace App\Services;

use App\Http\Controllers\ContactsController;
use Illuminate\Support\Facades\DB;

/**
 * AUDIENCIA de una campaña: a quién se envía, como REGLA que se resuelve en el momento
 * (así un contacto que hoy pasa a «Interés alto» entra en la siguiente campaña sin tocar
 * nada). Única fuente para la estimación y para el envío real: los números cuadran.
 *
 * Tipos (array `type` + datos):
 *   segment   → interes:[ids], tipo:[ids]   (dentro de un grupo vale CUALQUIERA; entre
 *                                             grupos, TODOS: «Interés alto + No cliente»)
 *   labels    → ids:[ids], match:any|all    (una o varias etiquetas libres)
 *   phonebook → id                          (lista fija; solo WhatsApp)
 *   provincia → values:[texto]              (contactos de Campañas de esas provincias)
 *   all       → —                           (todos los contactos de Campañas)
 *   audience  → id                          (una audiencia guardada)
 *
 * Siempre: solo contactos de CAMPAÑAS (nunca un cliente que solo es del Helpdesk), con el
 * dato del canal (teléfono o correo), sin bajas y sin repetir.
 */
class CampaignAudience
{
    public const GRUPOS = ['interes' => 'Interés', 'tipo' => 'Tipo'];

    /** Limpia lo que llega del navegador. Devuelve null si no es una audiencia válida. */
    public static function normalizar($in): ?array
    {
        if (is_string($in)) $in = json_decode($in, true);
        if (!is_array($in)) return null;
        $ints = fn ($v) => array_values(array_unique(array_filter(array_map('intval', (array) $v), fn ($x) => $x > 0)));
        switch ($in['type'] ?? '') {
            case 'segment':
                $a = ['type' => 'segment'];
                foreach (array_keys(self::GRUPOS) as $g) {
                    $ids = $ints($in[$g] ?? []);
                    // Solo etiquetas que de verdad son de ese grupo.
                    if ($ids) $ids = DB::table('labels')->whereIn('id', $ids)->where('grupo', $g)->pluck('id')->map(fn ($x) => (int) $x)->all();
                    if ($ids) $a[$g] = $ids;
                }
                return count($a) > 1 ? $a : null;
            case 'labels':
                $ids = $ints($in['ids'] ?? []);
                return $ids ? ['type' => 'labels', 'ids' => $ids, 'match' => ($in['match'] ?? '') === 'all' ? 'all' : 'any'] : null;
            case 'phonebook':
                $id = (int) ($in['id'] ?? 0);
                return $id > 0 ? ['type' => 'phonebook', 'id' => $id] : null;
            case 'provincia':
                $v = array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), (array) ($in['values'] ?? [])))));
                return $v ? ['type' => 'provincia', 'values' => array_slice($v, 0, 60)] : null;
            case 'all':
                return ['type' => 'all'];
            case 'audience':
                $id = (int) ($in['id'] ?? 0);
                return $id > 0 ? ['type' => 'audience', 'id' => $id] : null;
        }
        return null;
    }

    /**
     * Destinatarios de la audiencia para un canal.
     * @return array{0: array<int,array{wa_id:?string,email:?string,name:?string}>, 1:int, 2:string}
     *         [destinatarios, excluidos por baja, descripción legible]
     */
    public static function resolver(array $aud, string $canal): array
    {
        if ($aud['type'] === 'audience') {
            $g = DB::table('campaign_audiences')->where('id', $aud['id'])->first();
            $reglas = $g ? self::normalizar($g->rules) : null;
            if (!$reglas || $reglas['type'] === 'audience') return [[], 0, 'Audiencia no encontrada'];
            [$r, $ex] = self::resolver($reglas, $canal);
            return [$r, $ex, '👥 ' . $g->name];
        }

        $email = $canal === 'email';
        $desc = self::describir($aud);

        // Agenda: lista fija de números (no son fichas). Solo WhatsApp.
        if ($aud['type'] === 'phonebook') {
            if ($email) return [[], 0, $desc];
            $raw = DB::table('phonebook_contacts')->where('phonebook_id', $aud['id'])->get(['wa_id', 'name']);
            $bajas = array_flip(DB::table('contacts')->where('opted_out', 1)->whereNotNull('wa_id')->pluck('wa_id')
                ->map(fn ($w) => preg_replace('/\D/', '', (string) $w))->all());
            $out = []; $ex = 0; $vistos = [];
            foreach ($raw as $r) {
                $wa = preg_replace('/\D/', '', (string) $r->wa_id);
                if ($wa === '' || isset($vistos[$wa])) continue;
                $vistos[$wa] = true;
                if (isset($bajas[$wa])) { $ex++; continue; }
                $out[] = ['wa_id' => $wa, 'email' => null, 'name' => $r->name];
            }
            return [$out, $ex, $desc];
        }

        $q = DB::table('contacts as c');
        $tieneEtiqueta = fn ($ids) => fn ($w) => $w->select(DB::raw(1))->from('contact_labels as cl')
            ->whereColumn('cl.contact_id', 'c.id')->whereIn('cl.label_id', $ids);

        switch ($aud['type']) {
            case 'segment':
                foreach (array_keys(self::GRUPOS) as $g) {
                    if (!empty($aud[$g])) $q->whereExists($tieneEtiqueta($aud[$g]));
                }
                break;
            case 'labels':
                if ($aud['match'] === 'all') foreach ($aud['ids'] as $id) $q->whereExists($tieneEtiqueta([$id]));
                else $q->whereExists($tieneEtiqueta($aud['ids']));
                break;
            case 'provincia':
                $q->whereIn('c.provincia', $aud['values'])->whereRaw(ContactsController::SQL_CAMPANAS);
                break;
            case 'all':
                $q->whereRaw(ContactsController::SQL_CAMPANAS);
                break;
            default:
                return [[], 0, $desc];
        }

        $col = $email ? 'c.email' : 'c.wa_id';
        $raw = $q->whereNotNull($col)->where($col, '<>', '')->orderBy('c.id')->get(['c.wa_id', 'c.email', 'c.name', 'c.opted_out']);

        $out = []; $ex = 0; $vistos = [];
        foreach ($raw as $r) {
            $clave = $email ? mb_strtolower(trim((string) $r->email)) : preg_replace('/\D/', '', (string) $r->wa_id);
            if ($clave === '' || isset($vistos[$clave])) continue;
            $vistos[$clave] = true;
            if ((int) $r->opted_out === 1) { $ex++; continue; }
            $out[] = $email ? ['wa_id' => null, 'email' => $clave, 'name' => $r->name]
                            : ['wa_id' => $clave, 'email' => null, 'name' => $r->name];
        }
        return [$out, $ex, $desc];
    }

    /** Texto corto para el historial: «Interés alto + No cliente», «🏷 VIP o Feria», … */
    public static function describir(array $aud): string
    {
        $nombres = fn ($ids) => DB::table('labels')->whereIn('id', $ids)->orderBy('position')->orderBy('name')->pluck('name')->all();
        $txt = match ($aud['type']) {
            'segment'   => implode(' + ', array_filter(array_map(fn ($g) => !empty($aud[$g]) ? implode(' o ', $nombres($aud[$g])) : null, array_keys(self::GRUPOS)))),
            'labels'    => '🏷 ' . implode($aud['match'] === 'all' ? ' y ' : ' o ', $nombres($aud['ids'])),
            'phonebook' => '📒 ' . (DB::table('phonebooks')->where('id', $aud['id'])->value('name') ?? 'Agenda'),
            'provincia' => '📍 ' . implode(', ', $aud['values']),
            'all'       => 'Todos los contactos de Campañas',
            'audience'  => '👥 ' . (DB::table('campaign_audiences')->where('id', $aud['id'])->value('name') ?? 'Audiencia'),
            default     => '—',
        };
        return mb_substr($txt, 0, 250);
    }
}
