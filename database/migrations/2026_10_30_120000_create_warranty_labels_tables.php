<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Productos de una garantía: las etiquetas afectadas (por su código) y el catálogo de
 * modelos con el que se sabe qué producto del portal es cada una.
 *
 * El catálogo (modelos y sus referencias ZKONG ↔ AEME) se trae tal cual de la app de
 * garantías (database/data/device_models.json). La referencia AEME es el item_ref del
 * producto en el portal.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_models', function (Blueprint $t) {
            $t->id();
            $t->decimal('size', 5, 2)->nullable();
            $t->string('eink_colors', 8)->nullable();   // BW, BWR, BWRY
            $t->string('color', 20)->nullable();
            $t->string('variant', 60)->nullable();
            $t->boolean('has_batteries')->default(false);
            $t->string('battery_type', 30)->nullable();
            $t->unsignedTinyInteger('battery_quantity')->nullable();
            $t->timestamps();
        });

        Schema::create('device_model_references', function (Blueprint $t) {
            $t->id();
            $t->foreignId('device_model_id')->constrained('device_models')->cascadeOnDelete();
            $t->string('provider', 10);                  // zkong | aeme
            $t->string('name', 80);
            $t->unsignedBigInteger('pair_id')->nullable(); // su pareja del otro proveedor
            $t->timestamps();
            $t->index(['provider', 'name']);
        });

        Schema::create('warranty_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $t->string('code', 20);                       // etiqueta (9 caracteres) o MAC de antena (12)
            $t->string('kind', 3)->default('esl');        // esl | ap
            $t->string('lookup_state', 12)->default('pending'); // found | not_found | failed | pending
            $t->string('server', 60)->nullable();         // servidor donde está viva
            $t->string('store_name', 160)->nullable();
            $t->string('merchant_name', 160)->nullable();
            $t->boolean('online')->nullable();
            $t->dateTime('last_commu_at')->nullable();
            $t->string('server_model', 60)->nullable();   // el modelo tal como lo da el servidor
            $t->foreignId('device_model_id')->nullable()->constrained('device_models')->nullOnDelete();
            $t->string('aeme_ref', 80)->nullable();       // producto del portal (item_ref)
            $t->string('product_name', 200)->nullable();  // su nombre en el portal, guardado
            $t->boolean('model_manual')->default(false);  // el modelo lo eligió una persona
            $t->json('warnings')->nullable();             // avisos al comprobarla
            $t->dateTime('checked_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['warranty_id', 'code']);
            $t->index('code');
        });

        $data = json_decode(file_get_contents(database_path('data/device_models.json')), true);
        $now = now();
        DB::table('device_models')->insert(array_map(fn ($m) => $m + ['created_at' => $now, 'updated_at' => $now], $data['models']));
        DB::table('device_model_references')->insert(array_map(fn ($r) => $r + ['created_at' => $now, 'updated_at' => $now], $data['references']));
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_items');
        Schema::dropIfExists('device_model_references');
        Schema::dropIfExists('device_models');
    }
};
