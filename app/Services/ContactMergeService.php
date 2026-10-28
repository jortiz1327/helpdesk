<?php

namespace App\Services;

use App\Support\Telefono;
use Illuminate\Support\Facades\DB;

/**
 * Un cliente = una ficha. Fusión de contactos (manual desde Contactos, desde el aviso de
 * «posibles duplicados» y automática cuando la evidencia es inequívoca) y alta de teléfono
 * sin crear duplicados.
 */
class ContactMergeService
{
    /**
     * Fusiona $mergeId DENTRO de $keepId: todo lo que cuelga del absorbido pasa al principal,
     * se rellenan SOLO los huecos del principal y el absorbido se borra. En transacción.
     */
    public function fusionar(int $keepId, int $mergeId): void
    {
        if ($keepId === $mergeId) return;
        $keep  = DB::table('contacts')->where('id', $keepId)->first();
        $merge = DB::table('contacts')->where('id', $mergeId)->first();
        if (!$keep || !$merge) return;

        DB::transaction(function () use ($keep, $merge, $keepId, $mergeId) {
            // 1) Todo lo que cuelga del contacto pasa al principal.
            foreach (['messages', 'tickets', 'flow_sessions', 'flow_responses', 'form_submissions'] as $t) {
                DB::table($t)->where('contact_id', $mergeId)->update(['contact_id' => $keepId]);
            }
            // Etiquetas excluyentes: si chocan (los dos tienen «Interés…»), manda la del principal.
            $labels = \App\Models\Label::excluyentes(array_merge(
                DB::table('contact_labels')->where('contact_id', $mergeId)->pluck('label_id')->all(),
                DB::table('contact_labels')->where('contact_id', $keepId)->pluck('label_id')->all(),
            ));
            DB::table('contact_labels')->whereIn('contact_id', [$keepId, $mergeId])->delete();
            foreach ($labels as $lid) {
                DB::table('contact_labels')->insertOrIgnore(['contact_id' => $keepId, 'label_id' => $lid]);
            }

            // 2) Rellenar SOLO los huecos del principal (lo suyo manda).
            $upd = [];
            foreach (['name', 'email', 'wa_id', 'country_code', 'note', 'empresa', 'tienda', 'cargo', 'provincia', 'comentarios', 'sede_id'] as $col) {
                if (property_exists($keep, $col) && empty($keep->$col) && !empty($merge->$col)) $upd[$col] = $merge->$col;
            }
            // Se conserva la actividad más reciente de los dos.
            if (!empty($merge->last_time) && (empty($keep->last_time) || $merge->last_time > $keep->last_time)) {
                $upd['last_time']    = $merge->last_time;
                $upd['last_message'] = $merge->last_message;
            }
            $upd['unread'] = (int) $keep->unread + (int) $merge->unread;
            // Si cualquiera de los dos se dio de baja, la baja manda (más conservador).
            if ((int) $merge->opted_out === 1) $upd['opted_out'] = 1;
            // Área: la ficha unida es de todas las áreas de las dos.
            foreach (['en_soporte', 'en_campanas'] as $col) {
                if ((int) ($merge->$col ?? 0) === 1) $upd[$col] = 1;
            }

            // wa_id y email son únicos: se liberan del absorbido ANTES de copiarlos.
            DB::table('contacts')->where('id', $mergeId)->update(['wa_id' => null, 'email' => null]);
            DB::table('contacts')->where('id', $keepId)->update($upd);

            // 3) Fuera el duplicado (y sus «no son la misma persona», que ya no aplican).
            DB::table('contact_duplicate_ignores')->where('contact_a', $mergeId)->orWhere('contact_b', $mergeId)->delete();
            DB::table('contacts')->where('id', $mergeId)->delete();
        });
    }

    /**
     * Pone el teléfono a un contacto SIN crear duplicados y SIN fusionar nunca. Que alguien
     * escriba un número no prueba que sea suyo: fusionar aquí daba a quien lo tecleara los
     * tickets y los WhatsApp de esa otra ficha (y borraba contactos de Campañas). Si el
     * número ya es de otra ficha, no se toca nada y se devuelve su id para avisar; la unión
     * la decide un agente desde Contactos («Unir fichas»). Nunca pisa un teléfono guardado.
     */
    public function asignarTelefono(int $contactId, ?string $telefono): ?int
    {
        $tel = Telefono::normalizar($telefono);
        if (!$tel) return null;
        $c = DB::table('contacts')->where('id', $contactId)->first(['wa_id']);
        if (!$c || !empty($c->wa_id)) return null;

        $otro = DB::table('contacts')->where('wa_id', $tel)->where('id', '<>', $contactId)->value('id');
        if ($otro) return (int) $otro;
        DB::table('contacts')->where('id', $contactId)->update(['wa_id' => $tel]);
        return null;
    }
}
