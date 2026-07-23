<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
