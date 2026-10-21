<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * CAPTURA CRUDA de eventos de WhatsApp de números que NO son de Campañas (el 01 de
 * soporte con Coexistence). El historial y los contactos del móvil llegan UNA sola vez
 * (sincronización de las primeras 24 h) y en muchos bloques: `webhook_log` solo guarda
 * los últimos 200, así que aquí se conserva TODO, sin recortar, para procesarlo luego
 * (fase 2: tickets o chat de soporte). `processed_at` marca lo ya importado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_raw_events', function (Blueprint $t) {
            $t->id();
            $t->string('field', 40);                       // messages | history | smb_message_echoes | smb_app_state_sync | account_update…
            $t->string('waba_id', 40)->nullable();
            $t->string('phone_number_id', 40)->nullable();
            $t->longText('payload');                       // el `value` del change, tal cual
            $t->timestamp('processed_at')->nullable();
            $t->timestamp('created_at')->useCurrent();
            $t->index(['field', 'processed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_raw_events');
    }
};
