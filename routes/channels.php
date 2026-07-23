<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Canales de broadcasting.
|
| `tickets` es un canal PRIVADO: hay que autorizarse para escucharlo. Solo se
| suscribe quien puede acceder al Helpdesk — un responsable de campañas, aunque
| tenga cuenta, no recibe avisos de tickets.
*/
Broadcast::channel('tickets', function (User $user) {
    return $user->can('helpdesk.access');
});

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
