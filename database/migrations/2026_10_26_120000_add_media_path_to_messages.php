<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * COPIA LOCAL de los archivos del WhatsApp de soporte. Meta los borra a las pocas
 * semanas; `wa:media guardar` los baja al servidor (fotos, audios, documentos y
 * stickers; los vídeos no) y `wa:media purgar` los borra a los 6 meses.
 *   media_path  → ruta en el disco local (null = no hay copia)
 *   media_tries → intentos de descarga fallidos (se deja de insistir a los 5)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->string('media_path', 255)->nullable()->after('media_mime');
            $t->unsignedTinyInteger('media_tries')->default(0)->after('media_path');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->dropColumn(['media_path', 'media_tries']);
        });
    }
};
