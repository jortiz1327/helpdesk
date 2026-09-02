<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * FUNCIÓN de la cuenta de correo: 'soporte' (buzón de tickets: IMAP entrante + SMTP) o
 * 'campanas' (remitente de campañas por correo: SOLO SMTP). Se separan a propósito: una
 * campaña NO debe salir del buzón de soporte, porque las respuestas del cliente entrarían
 * como tickets. El sondeo IMAP (correo→ticket) solo mira las cuentas de soporte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $t) {
            $t->string('funcion', 20)->default('soporte')->after('id');   // soporte | campanas
        });
        DB::table('email_accounts')->update(['funcion' => 'soporte']);    // las existentes son de soporte
    }

    public function down(): void
    {
        Schema::table('email_accounts', fn (Blueprint $t) => $t->dropColumn('funcion'));
    }
};
