<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /*
         * ALINEA la zona horaria de MySQL con la de la app (Europe/Madrid), SEA CUAL SEA
         * la del servidor. En un Plesk con el sistema en UTC, MySQL escribía NOW() y los
         * DEFAULT CURRENT_TIMESTAMP en UTC mientras PHP (Carbon) lo hacía en Madrid: dos
         * relojes con 1-2 h de desfase en la misma tabla (un ticket recién creado salía
         * «hace 2 h»). Se fija el OFFSET ACTUAL de la app en cada conexión, así que MySQL
         * y PHP escriben la misma hora. Se usa el offset (no el nombre de zona) para no
         * depender de las tablas de zonas de MySQL, y se recalcula por arranque, con lo
         * que respeta el cambio de horario de verano/invierno.
         */
        $tz     = config('app.timezone', 'Europe/Madrid');
        $offset = (new \DateTime('now', new \DateTimeZone($tz)))->format('P');   // p. ej. +02:00
        config(['database.connections.mysql.timezone' => $offset]);
    }

    public function boot(): void
    {
        /*
         * Bypass del superadministrador: se le conceden TODOS los permisos,
         * incluidos los que añadamos en el futuro, sin tener que reasignárselos.
         * Devolver null deja que el resto de comprobaciones sigan su curso normal.
         */
        Gate::before(function (User $user) {
            return $user->hasRole(config('rbac.super_role')) ? true : null;
        });
    }
}
