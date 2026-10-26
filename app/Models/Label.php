<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

class Label extends Model
{
    public $timestamps = false;
    protected $guarded = ['id'];

    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class, 'contact_labels');
    }

    /**
     * ETIQUETAS EXCLUYENTES: de cada `grupo` (interés, tipo…) se queda solo UNA, la
     * última de la lista (la que se acaba de poner). Las que no tienen grupo, todas.
     */
    public static function excluyentes(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $grupos = DB::table('labels')->whereIn('id', $ids)->whereNotNull('grupo')->pluck('grupo', 'id');
        $visto = [];
        foreach (array_reverse($ids) as $id) {
            $g = $grupos[$id] ?? null;
            if ($g !== null) {
                if (isset($visto[$g])) continue;
                $visto[$g] = true;
            }
            $quedan[] = $id;
        }
        return array_reverse($quedan ?? []);
    }

    /** Quita a esos contactos las etiquetas del MISMO grupo que $labelId (antes de ponerla). */
    public static function quitarHermanas(array $contactIds, int $labelId): void
    {
        $grupo = DB::table('labels')->where('id', $labelId)->value('grupo');
        if ($grupo === null || !$contactIds) return;
        $hermanas = DB::table('labels')->where('grupo', $grupo)->where('id', '!=', $labelId)->pluck('id');
        if ($hermanas->isNotEmpty()) {
            DB::table('contact_labels')->whereIn('contact_id', $contactIds)->whereIn('label_id', $hermanas)->delete();
        }
    }
}
