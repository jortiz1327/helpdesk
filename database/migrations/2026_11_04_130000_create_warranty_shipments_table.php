<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Ya lo he enviado»: el cliente avisa desde el portal de que nos ha mandado el material
 * (fecha, transportista, seguimiento, bultos y dispositivos). Va por ticket, porque se
 * puede avisar antes de que exista la garantía; warranty_id se rellena si ya la hay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_shipments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $t->foreignId('warranty_id')->nullable()->constrained('warranties')->nullOnDelete();
            $t->string('email', 190);
            $t->date('shipped_on');
            $t->string('carrier', 60)->nullable();
            $t->string('tracking', 80)->nullable();
            $t->unsignedSmallInteger('packages')->default(1);
            $t->unsignedSmallInteger('units')->nullable();
            $t->string('note', 300)->nullable();
            $t->timestamps();
            $t->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_shipments');
    }
};
