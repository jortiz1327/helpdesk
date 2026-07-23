<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * COPIAS del correo (Cc / Cco) en cada mensaje.
 *
 * Hasta ahora solo se guardaba el remitente, así que al responder se dejaba fuera
 * a todo el que viniera en copia. Se guardan como lista separada por comas: son
 * cuatro direcciones, no hace falta una tabla aparte.
 *
 * OJO con el Cco de los correos ENTRANTES: no se puede guardar porque el servidor
 * no lo manda (es el sentido del Cco). La columna existe para lo que enviamos
 * NOSOTROS, donde sí sabemos a quién se lo hemos mandado en oculto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->text('cc')->nullable()->after('wa_id');
            $table->text('bcc')->nullable()->after('cc');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn(['cc', 'bcc']);
        });
    }
};
