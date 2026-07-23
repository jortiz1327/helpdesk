<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Imágenes pegadas/insertadas EN LÍNEA en el editor de respuestas y notas.
 * Se guardan en disco privado y se sirven por URL FIRMADA (no lleva token del
 * usuario en el HTML). Aquí solo va el índice para poder servirlas y limpiarlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inline_uploads', function (Blueprint $t) {
            $t->id();
            $t->string('path');                       // ruta en disco 'local'
            $t->string('mime', 80);
            $t->unsignedInteger('size')->default(0);
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inline_uploads');
    }
};
