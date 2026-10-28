<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * ÁREA de una ficha de contacto: Soporte, Campañas o las dos. Se guarda en la propia ficha
 * (`contacts.en_soporte`, `contacts.en_campanas`) en vez de deducirla en cada consulta.
 * Antes se deducía («tiene tickets», «tiene teléfono»…) y por eso borrar el último ticket
 * de un cliente lo pasaba a Campañas, o un usuario de Campañas podía tocar fichas de
 * clientes de soporte. Única definición de quién ve y quién edita cada ficha.
 *
 * Las marcas se PONEN solas con la actividad y no se quitan (la única excepción es
 * borrar la conversación de Campañas, que es sacarla de Campañas a propósito):
 *   · Soporte  → un ticket (alta, cambio de solicitante, fusión) o un mensaje del 01.
 *   · Campañas → un WhatsApp del 51, una etiqueta, o una ficha con teléfono dada de alta
 *                fuera de Soporte (alta a mano, masiva, importación, «nueva conversación»).
 */
class ContactArea
{
    /** Condición SQL (alias `c`) de las fichas de Campañas: audiencias, agendas, listas. */
    public const SQL_CAMPANAS = 'c.en_campanas = 1';

    /** Condición SQL (alias `c`) de las fichas de Soporte. */
    public const SQL_SOPORTE = 'c.en_soporte = 1';

    /**
     * Cómo se DEDUCÍA hasta la 1.5.13 que una ficha era de Campañas. Solo se usa para
     * rellenar las marcas la primera vez (migración), de modo que las audiencias de
     * campañas salen idénticas: ni un destinatario más ni uno menos.
     */
    public const SQL_CAMPANAS_DEDUCIDO = "(EXISTS (SELECT 1 FROM messages mw WHERE mw.contact_id = c.id AND mw.channel = 'whatsapp' AND mw.funcion = 'campanas')
                OR EXISTS (SELECT 1 FROM contact_labels clc WHERE clc.contact_id = c.id)
                OR (c.wa_id IS NOT NULL
                    AND NOT EXISTS (SELECT 1 FROM tickets tk WHERE tk.contact_id = c.id)
                    AND NOT EXISTS (SELECT 1 FROM messages ms WHERE ms.contact_id = c.id AND ms.funcion = 'soporte')))";

    /** Cómo se deduce que una ficha es de Soporte (para rellenar las marcas). */
    public const SQL_SOPORTE_DEDUCIDO = "(EXISTS (SELECT 1 FROM tickets tk WHERE tk.contact_id = c.id)
                OR EXISTS (SELECT 1 FROM messages ms WHERE ms.contact_id = c.id AND ms.funcion = 'soporte'))";

    /** Rellena las marcas de TODAS las fichas a partir de su actividad (solo suma). */
    public static function recalcular(): void
    {
        DB::update('UPDATE contacts c SET c.en_soporte = 1 WHERE c.en_soporte = 0 AND ' . self::SQL_SOPORTE_DEDUCIDO);
        DB::update('UPDATE contacts c SET c.en_campanas = 1 WHERE c.en_campanas = 0 AND ' . self::SQL_CAMPANAS_DEDUCIDO);
    }

    /** Marca fichas como de Soporte. */
    public static function soporte(int|array $ids): void
    {
        self::marcar($ids, 'en_soporte');
    }

    /** Marca fichas como de Campañas. */
    public static function campanas(int|array $ids): void
    {
        self::marcar($ids, 'en_campanas');
    }

    /**
     * Alta con teléfono FUERA de Soporte (a mano, masiva, importación, nueva conversación):
     * es de Campañas salvo que ya sea un cliente de Soporte. Es la misma regla que se
     * deducía antes («tiene teléfono y no es del Helpdesk»).
     */
    public static function porTelefono(int|array $ids): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        if (!$ids) return;
        DB::table('contacts')->whereIn('id', $ids)->whereNotNull('wa_id')
            ->where('en_soporte', 0)->where('en_campanas', 0)->update(['en_campanas' => 1]);
    }

    protected static function marcar(int|array $ids, string $col): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) $ids)));
        if (!$ids) return;
        DB::table('contacts')->whereIn('id', $ids)->where($col, 0)->update([$col => 1]);
    }

    /**
     * ¿Puede este usuario VER y tocar esta ficha? Si es de Soporte, con Helpdesk; si es
     * de Campañas, con Campañas (basta una de las dos si es de ambas). Una ficha aún sin
     * área (p. ej. alta a mano solo con correo) la ve cualquiera con acceso a Contactos.
     */
    public static function puedeVer(User $u, object $c): bool
    {
        $soporte  = (int) ($c->en_soporte ?? 0) === 1;
        $campanas = (int) ($c->en_campanas ?? 0) === 1;
        if (!$soporte && !$campanas) return true;
        return ($soporte && $u->can('helpdesk.access')) || ($campanas && $u->can('campaigns.access'));
    }

    /**
     * ¿Puede cambiar su IDENTIDAD (correo, teléfono, fusionarla)? El correo es la llave
     * del portal y el teléfono la del WhatsApp de soporte: en una ficha de Soporte hace
     * falta Helpdesk, aunque la ficha sea también de Campañas.
     */
    public static function puedeIdentidad(User $u, object $c): bool
    {
        if ((int) ($c->en_soporte ?? 0) === 1) return $u->can('helpdesk.access');
        return self::puedeVer($u, $c);
    }

    /** Condición SQL (alias `c`) con las fichas que puede ver el usuario; null = todas. */
    public static function sqlVisibles(User $u): ?string
    {
        $h = $u->can('helpdesk.access');
        $k = $u->can('campaigns.access');
        if ($h && $k) return null;
        $partes = ['(c.en_soporte = 0 AND c.en_campanas = 0)'];
        if ($h) $partes[] = self::SQL_SOPORTE;
        if ($k) $partes[] = self::SQL_CAMPANAS;
        return '(' . implode(' OR ', $partes) . ')';
    }

    /** Fichas (de esa lista) que el usuario NO puede ver. Vacío = todas permitidas. */
    public static function noVisibles(User $u, array $ids): array
    {
        $ids = array_values(array_filter(array_map('intval', $ids)));
        if (!$ids) return [];
        return DB::table('contacts')->whereIn('id', $ids)->get(['id', 'en_soporte', 'en_campanas'])
            ->reject(fn ($c) => self::puedeVer($u, $c))->pluck('id')->map(fn ($i) => (int) $i)->all();
    }
}
