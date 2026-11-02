<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Presupuestos, albaranes y precios viven en el portal AEME: fuera la tarifa propia,
 * las líneas de los documentos generados aquí y los documentos que no son el informe
 * de resolución (con sus ficheros subidos).
 */
return new class extends Migration
{
    public function up(): void
    {
        $fuera = DB::table('warranty_documents')->where('kind', '!=', 'informe');
        $paths = (clone $fuera)->whereNotNull('path')->pluck('path')->all();
        if ($paths) {
            Storage::disk('local')->delete($paths);
        }
        Schema::dropIfExists('warranty_document_lines');
        $fuera->delete();
        Schema::dropIfExists('warranty_prices');
        DB::table('warranty_settings')->where('key', 'documents')->delete();
    }

    public function down(): void
    {
        Schema::create('warranty_prices', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 10);
            $t->string('ref', 80);
            $t->string('label', 160)->nullable();
            $t->decimal('price', 10, 2)->default(0);
            $t->timestamps();
            $t->unique(['kind', 'ref']);
        });
        Schema::create('warranty_document_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warranty_document_id')->constrained('warranty_documents')->cascadeOnDelete();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->string('ref', 80)->nullable();
            $t->string('description', 300);
            $t->json('codes')->nullable();
            $t->decimal('qty', 10, 2)->default(1);
            $t->decimal('price', 10, 2)->default(0);
        });
    }
};
