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

    /** La marca recordada del contacto (usuario de varios hoteles de una misma marca) si el vínculo sigue vigente; null si no hay o caducó. */
    public static function vigenteMarca(?int $contactId): ?object
    {
        if (! $contactId) {
            return null;
        }
        $c = DB::table('contacts')->where('id', $contactId)->whereNull('sede_id')->first(['marca_id', 'sede_verificada_at']);
        if (! $c || ! $c->marca_id || ! $c->sede_verificada_at || strtotime((string) $c->sede_verificada_at) < strtotime('-' . self::MESES . ' months')) {
            return null;
        }
        $m = DB::table('marcas')->where('id', $c->marca_id)->where('active', 1)->first(['id', 'name']);
        return $m ? (object) ['id' => (int) $m->id, 'name' => (string) $m->name] : null;
    }

    /** Lo que enseña la ficha del ticket: sede › marca › grupo (solo marca › grupo si está enlazado a la marca), quién y cuándo la confirmó, y si sigue vigente. Null si no hay nada. */
    public static function resumen(?int $sedeId, ?string $verificadaAt, ?string $por, ?int $marcaId = null): ?array
    {
        if (! $sedeId && ! $marcaId) {
            return null;
        }
        if ($sedeId) {
            $s = DB::table('sedes as s')->leftJoin('marcas as m', 'm.id', '=', 's.marca_id')->leftJoin('grupos as g', 'g.id', '=', 'm.grupo_id')
                ->where('s.id', $sedeId)->first(['s.name as sede', 'm.name as marca', 'g.name as grupo']);
        } else {
            $s = DB::table('marcas as m')->leftJoin('grupos as g', 'g.id', '=', 'm.grupo_id')
                ->where('m.id', $marcaId)->first(['m.name as marca', 'g.name as grupo']);
            if ($s) {
                $s->sede = null;
            }
        }
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
            return self::recordarMarca($contactId, array_values($claves));
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
        DB::table('contacts')->where('id', $contactId)->update(['sede_id' => $sede, 'marca_id' => null, 'sede_verificada_at' => now(), 'sede_verificada_por' => 'correo']);
        return $igual ? null : $nombre;
    }

    /**
     * Varios hoteles activos (director de marca, grupo de hoteles): no se adivina cuál, pero si TODOS son de la misma marca (ya configurada en el
     * panel: no vale «Sin marca») el contacto se enlaza a esa marca. Los hoteles que aún no existen en el panel no se crean aquí: sin ellos no hay marca segura.
     *
     * @param  array<int, array{hotel:string, hotel_id?:string}>  $sitios  uno por hotel distinto
     */
    private static function recordarMarca(int $contactId, array $sitios): ?string
    {
        $marcas = [];
        foreach ($sitios as $x) {
            $s = self::buscarSede(trim((string) $x['hotel']), trim((string) ($x['hotel_id'] ?? '')));
            $m = $s ? DB::table('sedes')->where('id', $s)->value('marca_id') : null;
            if (! $m) {
                return null;
            }
            $marcas[(int) $m] = true;
        }
        if (count($marcas) !== 1) {
            return null;
        }
        $marcaId = (int) array_key_first($marcas);
        $marca = DB::table('marcas')->where('id', $marcaId)->first(['name']);
        if (! $marca || mb_strtolower(trim((string) $marca->name)) === 'sin marca') {
            return null;
        }
        $actual = DB::table('contacts')->where('id', $contactId)->first(['sede_id', 'marca_id', 'sede_verificada_at', 'sede_verificada_por']);
        if (! $actual) {
            return null;
        }
        // Lo que un agente fijó a mano (y sigue vigente) no lo pisa el correo.
        if (($actual->sede_id || $actual->marca_id) && $actual->sede_verificada_por === 'agente'
            && $actual->sede_verificada_at && strtotime((string) $actual->sede_verificada_at) >= strtotime('-' . self::MESES . ' months')) {
            return null;
        }
        $igual = ! $actual->sede_id && (int) $actual->marca_id === $marcaId;
        $reciente = $actual->sede_verificada_at && strtotime((string) $actual->sede_verificada_at) >= strtotime('-1 day');
        if ($igual && $reciente) {
            return null;
        }
        DB::table('contacts')->where('id', $contactId)->update(['sede_id' => null, 'marca_id' => $marcaId, 'sede_verificada_at' => now(), 'sede_verificada_por' => 'correo']);
        return $igual ? null : (string) $marca->name;
    }

    /** La sede de ese hotel si ya existe en el panel (por id de AdminHoteles o por nombre); no crea nada. */
    private static function buscarSede(string $nombre, string $hotelId): ?int
    {
        if ($hotelId !== '' && ($id = DB::table('sedes')->where('admin_hotel_id', $hotelId)->value('id'))) {
            return (int) $id;
        }
        $n = HotelsApi::norm($nombre);
        foreach (DB::table('sedes')->get(['id', 'name']) as $s) {
            if ($n !== '' && HotelsApi::norm((string) $s->name) === $n) {
                return (int) $s->id;
            }
        }
        return null;
    }

    /**
     * Un contacto sin correo (típico de WhatsApp) da el suyo y AdminHoteles lo confirma: se guarda en su ficha para no volver a pedirlo.
     * El correo es único: si ya es de otra ficha no se toca nada y se devuelve su id para que una persona las una (Contactos › Unir fichas).
     *
     * @return array{estado:string, otro?:int}|null  null si no había nada que hacer (el contacto ya tiene correo o el correo no es válido)
     */
    public static function guardarCorreo(?int $contactId, string $email): ?array
    {
        $email = mb_strtolower(trim($email));
        if (! $contactId || $email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        $c = DB::table('contacts')->where('id', $contactId)->first(['email']);
        if (! $c || trim((string) $c->email) !== '') {
            return null;
        }
        $otro = DB::table('contacts')->whereRaw('LOWER(email) = ?', [$email])->where('id', '<>', $contactId)->value('id');
        if ($otro) {
            return ['estado' => 'duplicado', 'otro' => (int) $otro];
        }
        DB::table('contacts')->where('id', $contactId)->update(['email' => $email]);
        return ['estado' => 'guardado'];
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
