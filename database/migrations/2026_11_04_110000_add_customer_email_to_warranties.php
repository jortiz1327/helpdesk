<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Correo del cliente para la resolución de la garantía (siempre va por correo). Cuando el
 * ticket llegó por WhatsApp hay que preguntárselo: se guarda aquí, en la garantía, sin
 * tocar la ficha del contacto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $t) {
            $t->string('customer_email', 190)->nullable()->after('ticket_id');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $t) {
            $t->dropColumn('customer_email');
        });
    }
};
