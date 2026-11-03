<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        /*
         * El «Chat en vivo» (conversaciones de WhatsApp) es un apartado de CAMPAÑAS,
         * pero comparte endpoints con el Helpdesk (mismo controlador de conversaciones
         * y de envío). Por eso se accede/opera con permisos de CUALQUIERA de las dos
         * áreas: así un encargado de campañas (sin helpdesk.access ni tickets.reply)
         * ve y responde su Chat en vivo, y el Helpdesk sigue exigiendo tickets.reply
         * para responder tickets (campaigns.access es falso para sus roles).
         */
        Gate::define('inbox.access', fn (User $u) => $u->can('campaigns.access') || $u->can('helpdesk.access'));
        Gate::define('inbox.reply',  fn (User $u) => $u->can('campaigns.access') || $u->can('tickets.reply'));

        /*
         * Freno del PORTAL público por IP: 30/min, que es lo que protege el pedido de código
         * (manda correos). Las fotos de una garantía (una llamada cada una: 10 etiquetas son
         * 30) y el buscador de direcciones (una por pausa al escribir) van con pase y llevan
         * su propio cupo, así que tienen un margen aparte para no tumbar el resto.
         */
        RateLimiter::for('portal', function (Request $r) {
            $action = (string) $r->query('action');
            if ($action === 'warranty-photo') return Limit::perMinute(300)->by('pf|' . $r->ip());
            if (str_starts_with($action, 'address-')) return Limit::perMinute(120)->by('pd|' . $r->ip());
            return Limit::perMinute(30)->by('p|' . $r->ip());
        });
    }
}
