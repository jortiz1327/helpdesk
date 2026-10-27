<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * messages.media_mime era de 64 caracteres y los tipos de Office se pasan
 * («application/vnd.openxmlformats-officedocument.spreadsheetml.sheet» son 65):
 * un Excel por WhatsApp hacía fallar la importación del historial. 255 sobra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->string('media_mime', 255)->nullable()->change();
        });
    }

    public function down(): void
    {
        // No se recorta de vuelta: truncaría tipos ya guardados.
    }
};
