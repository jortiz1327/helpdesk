<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjuntos EN LÍNEA de correo: guardamos el Content-ID (para casar los «cid:» del
 * HTML con su imagen) y una marca `inline` (imagen incrustada en el cuerpo, que NO
 * debe aparecer también en la tira de adjuntos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->string('content_id', 255)->nullable()->after('mime');
            $table->boolean('inline')->default(false)->after('content_id');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table) {
            $table->dropColumn(['content_id', 'inline']);
        });
    }
};
