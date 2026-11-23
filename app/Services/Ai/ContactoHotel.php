<?php

namespace App\Services\Ai;

use Illuminate\Support\Facades\DB;

/**
 * Memoria de «de qué hotel es este contacto». Cuando AdminHoteles confirma que su correo es de UN solo hotel, el contacto se enlaza a una
 * sede (grupo › marca › sede) y, la próxima vez que escriba, la IA ya sabe de qué hotel es. Es solo una ayuda para no volver a preguntar:
 * NO sustituye a la verificación, que se repite en cada ticket (si el usuario ya no figura o está desactivado, no se le atiende).
 * El vínculo caduca a los 12 meses desde que se confirmó (por el correo o por un agente).
 */
class ContactoHotel
{
    public const MESES = 12;

    /** La sede recordada del contacto si el vínculo sigue vigente; null si no hay, caducó o la sede está inactiva. */
    public static function vigente(?int $contactId): ?object
    {
        if (! $contactId) {
            return null;
        }
        $c = DB::table('contacts')->where('id', $contactId)->first(['sede_id', 'sede_verificada_at', 'sede_verificada_por']);
        if (! $c || ! $c->sede_id || ! $c->sede_verificada_at || strtotime((string) $c->sede_verificada_at) < strtotime('-' . self::MESES . ' months')) {
            return null;
        }
        $s = DB::table('sedes')->where('id', $c->sede_id)->where('active', 1)->first(['id', 'name', 'admin_hotel_id']);
        return $s ? (object) ['id' => (int) $s->id, 'name' => (string) $s->name, 'verificada' => (string) $c->sede_verificada_at, 'por' => (string) $c->sede_verificada_por] : null;
    }

    /** Lo que enseña la ficha del ticket: sede › marca › grupo, quién y cuándo la confirmó, y si el vínculo sigue vigente. Null si no hay sede. */
    public static function resumen(?int $sedeId, ?string $verificadaAt, ?string $por): ?array
    {
        if (! $sedeId) {
            return null;
        }
        $s = DB::table('sedes as s')->leftJoin('marcas as m', 'm.id', '=', 's.marca_id')->leftJoin('grupos as g', 'g.id', '=', 'm.grupo_id')
            ->where('s.id', $sedeId)->first(['s.name as sede', 'm.name as marca', 'g.name as grupo']);
        if (! $s) {
            return null;
        }
        $hasta = $verificadaAt ? date('Y-m-d', strtotime($verificadaAt . ' +' . self::MESES . ' months')) : null;
        return [
            'sede' => $s->sede, 'marca' => $s->marca, 'grupo' => $s->grupo,
            'por' => $por ?: null, 'desde' => $verificadaAt ? substr($verificadaAt, 0, 10) : null, 'hasta' => $hasta,
            'vigente' => (bool) ($verificadaAt && $hasta >= date('Y-m-d')),
        ];
    }

    /**
     * Enlaza el contacto al hotel donde AdminHoteles ha confirmado su correo. Solo si todos los sitios activos son del mismo hotel
     * (con varios hoteles no se adivina cuál). Devuelve el nombre de la sede si se ha creado o cambiado el vínculo; null si no hizo falta.
     *
     * @param  array<int, array{hotel:string, hotel_id?:string, activo?:?bool}>  $sitios
     */
    public static function recordar(?int $contactId, array $sitios, string $grupo = ''): ?string
    {
        if (! $contactId || ! $sitios) {
            return null;
        }
        $claves = [];
        foreach ($sitios as $x) {
            $n = HotelsApi::norm((string) ($x['hotel'] ?? ''));
            if ($n === '') {
                return null;   // sin nombre de hotel no hay a qué enlazar
            }
            $claves[$n] = $x;
        }
        if (count($claves) !== 1) {
            return null;
        }
        $x = reset($claves);
        $nombre = trim((string) $x['hotel']);
        $hotelId = trim((string) ($x['hotel_id'] ?? ''));

        $actual = DB::table('contacts')->where('id', $contactId)->first(['sede_id', 'sede_verificada_at', 'sede_verificada_por']);
        if (! $actual) {
            return null;
        }
        $sede = self::sede($nombre, $hotelId, $grupo);
        // Un vínculo puesto a mano por un agente (y aún vigente) no lo pisa el correo: manda quien lo corrigió.
        if ($actual->sede_id && (int) $actual->sede_id !== $sede && $actual->sede_verificada_por === 'agente'
            && $actual->sede_verificada_at && strtotime((string) $actual->sede_verificada_at) >= strtotime('-' . self::MESES . ' months')) {
            return null;
        }
        $igual = (int) $actual->sede_id === $sede;
        $reciente = $actual->sede_verificada_at && strtotime((string) $actual->sede_verificada_at) >= strtotime('-1 day');
        if ($igual && $reciente) {
            return null;
        }
        DB::table('contacts')->where('id', $contactId)->update(['sede_id' => $sede, 'sede_verificada_at' => now(), 'sede_verificada_por' => 'correo']);
        return $igual ? null : $nombre;
    }

    /** La sede de ese hotel (por id de AdminHoteles o por nombre); si no existe se crea bajo su grupo, en la marca «Sin marca». */
    private static function sede(string $nombre, string $hotelId, string $grupo): int
    {
        $q = DB::table('sedes');
        if ($hotelId !== '' && ($id = (clone $q)->where('admin_hotel_id', $hotelId)->value('id'))) {
            return (int) $id;
        }
        $n = HotelsApi::norm($nombre);
        foreach (DB::table('sedes')->get(['id', 'name', 'admin_hotel_id']) as $s) {
            if (HotelsApi::norm((string) $s->name) === $n) {
                if ($hotelId !== '' && ! $s->admin_hotel_id) {
                    DB::table('sedes')->where('id', $s->id)->update(['admin_hotel_id' => $hotelId]);
                }
                return (int) $s->id;
            }
        }
        $grupoNombre = trim($grupo) !== '' ? trim($grupo) : 'Sin asignar';
        $g = DB::table('grupos')->whereRaw('LOWER(name) = ?', [mb_strtolower($grupoNombre)])->value('id')
            ?: DB::table('grupos')->insertGetId(['name' => $grupoNombre, 'color' => '#64748b', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $m = DB::table('marcas')->where('grupo_id', $g)->whereRaw('LOWER(name) = ?', ['sin marca'])->value('id')
            ?: DB::table('marcas')->insertGetId(['grupo_id' => $g, 'name' => 'Sin marca', 'active' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return (int) DB::table('sedes')->insertGetId([
            'marca_id' => $m, 'name' => mb_substr($nombre, 0, 160), 'admin_hotel_id' => $hotelId !== '' ? $hotelId : null,
            'origen' => 'adminhoteles', 'active' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
