<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * REGISTRO DE ACCIONES (auditoría). Append-only: qué hizo cada usuario, en qué
 * apartado y sobre qué. Lo llena un middleware automáticamente en cada acción que
 * cambia datos. Solo lo consulta el superadmin.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable()->index();
            $t->string('user_name', 120)->nullable();   // copia, para que sobreviva si se borra el usuario
            $t->string('section', 40)->index();          // Helpdesk, Contactos, Organización, Config…
            $t->string('action', 60);                    // clave de la acción (reply, save, delete…)
            $t->string('summary', 300);                  // frase legible
            $t->string('subject', 120)->nullable();      // referencia (TK-…, #id…)
            $t->string('method', 8)->nullable();
            $t->string('ip', 45)->nullable();
            $t->timestamp('created_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_log');
    }
};
