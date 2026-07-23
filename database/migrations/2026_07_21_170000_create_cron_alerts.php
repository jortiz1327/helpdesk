<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AVISOS DE CRON. Los correos de crones fallidos NO son tickets de cliente:
 * nadie contesta a un cron. Van a su propio apartado, sin SLA, sin turno y sin
 * acuse de recibo (contestaría a un `noreply@`).
 *
 * Un cron roto cada 5 minutos manda ~288 correos al día. Por eso se AGRUPAN: un
 * aviso por cron, con contador de fallos, y cada correo entra como una ejecución
 * más del histórico. La clave de agrupación es el NOMBRE DEL CRON + LOS PARÁMETROS
 * del comando, porque el mismo script corre para varios clientes y mezclarlos
 * sería inútil (decisión del usuario, 21-jul-2026).
 *
 * OJO: el nombre bueno es el del campo «Cron Job Name» del CUERPO, no el del
 * asunto —ahí va el título que le puso osTicket y NO coinciden—.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Canal propio para que los avisos no se mezclen con la bandeja de soporte.
        DB::statement("ALTER TABLE tickets  MODIFY channel ENUM('whatsapp','email','web','cron') NOT NULL DEFAULT 'whatsapp'");
        DB::statement("ALTER TABLE messages MODIFY channel ENUM('whatsapp','email','web','cron') NOT NULL DEFAULT 'whatsapp'");

        Schema::create('cron_alerts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id');
            $table->string('cron_key', 191)->unique();   // nombre + parámetros, normalizado
            $table->string('cron_name', 190);
            $table->string('params', 190)->nullable();   // «farmacia=scorazon», «buffet=13 service=43»
            $table->string('expression', 60)->nullable();// p. ej. cada cinco minutos
            $table->text('command')->nullable();

            $table->unsignedInteger('fails')->default(0);
            $table->timestamp('first_at')->nullable();
            $table->timestamp('last_at')->nullable();
            $table->string('last_exit_code', 12)->nullable();
            $table->string('last_reason', 190)->nullable();
            $table->text('last_output')->nullable();
            $table->timestamps();

            $table->index('last_at');
            $table->foreign('ticket_id')->references('id')->on('tickets')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cron_alerts');
        DB::statement("ALTER TABLE messages MODIFY channel ENUM('whatsapp','email','web') NOT NULL DEFAULT 'whatsapp'");
        DB::statement("ALTER TABLE tickets  MODIFY channel ENUM('whatsapp','email','web') NOT NULL DEFAULT 'whatsapp'");
    }
};
