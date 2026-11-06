<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Remesas a ZKONG completas: el número de RMA que da ZKONG, la vuelta (cuando llegan las
 * reposiciones: fecha, unidades, transportista y tracking) y el coste de cada etiqueta,
 * que se copia del portal al enviar la remesa para que las cuentas de una remesa vieja no
 * cambien si luego cambia el precio de coste en el portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zkong_batches', function (Blueprint $t) {
            $t->string('rma_number', 60)->nullable()->after('code');
            $t->dateTime('returned_at')->nullable()->after('units_received');
            $t->unsignedInteger('units_returned')->nullable()->after('returned_at');
            $t->string('return_carrier', 80)->nullable()->after('units_returned');
            $t->string('return_tracking', 120)->nullable()->after('return_carrier');
        });
        Schema::table('warranty_items', function (Blueprint $t) {
            $t->decimal('zkong_unit_cost', 10, 2)->nullable()->after('zkong_result_note');
            $t->string('zkong_cost_source', 12)->nullable()->after('zkong_unit_cost');   // coste | proveedor
        });
    }

    public function down(): void
    {
        Schema::table('warranty_items', function (Blueprint $t) {
            $t->dropColumn(['zkong_unit_cost', 'zkong_cost_source']);
        });
        Schema::table('zkong_batches', function (Blueprint $t) {
            $t->dropColumn(['rma_number', 'returned_at', 'units_returned', 'return_carrier', 'return_tracking']);
        });
    }
};
