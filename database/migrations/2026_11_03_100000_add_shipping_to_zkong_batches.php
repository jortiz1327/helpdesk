<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Envío de las remesas a ZKONG, a mano (no hay API del transportista): fecha, unidades,
 * transportista, tracking y albarán al enviarla; fecha y unidades cuando ZKONG confirma
 * que la ha recibido; motivo si se anula. Cada paso queda en la línea de tiempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zkong_batches', function (Blueprint $t) {
            $t->unsignedInteger('units_sent')->nullable()->after('sent_at');
            $t->string('carrier', 80)->nullable()->after('units_sent');
            $t->string('tracking', 120)->nullable()->after('carrier');
            $t->string('shipping_ref', 120)->nullable()->after('tracking');     // referencia / albarán
            $t->dateTime('received_at')->nullable()->after('shipping_ref');    // ZKONG confirma la recepción
            $t->unsignedInteger('units_received')->nullable()->after('received_at');
            $t->dateTime('cancelled_at')->nullable()->after('closed_at');
            $t->string('cancel_reason', 500)->nullable()->after('cancelled_at');
        });

        Schema::create('zkong_batch_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('zkong_batch_id')->constrained('zkong_batches')->cascadeOnDelete();
            $t->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('type', 20);          // creada | enviada | envio | recibida | respuesta | cerrada | reabierta | anulada
            $t->string('text', 300);
            $t->text('note')->nullable();
            $t->dateTime('at');              // cuándo pasó (la fecha que se apunta, no la de hoy)
            $t->timestamp('created_at')->useCurrent();
            $t->index(['zkong_batch_id', 'at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zkong_batch_events');
        Schema::table('zkong_batches', function (Blueprint $t) {
            $t->dropColumn(['units_sent', 'carrier', 'tracking', 'shipping_ref', 'received_at', 'units_received', 'cancelled_at', 'cancel_reason']);
        });
    }
};
