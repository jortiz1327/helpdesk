<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cuándo nos llegó un mensaje de WhatsApp. created_at es la hora a la que lo escribió el
 * cliente (la que manda WhatsApp); si su móvil estaba sin conexión, llega minutos después.
 * Con las dos, la conversación se ordena como en el móvil y se avisa del retraso.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('messages', 'received_at')) return;
        Schema::table('messages', function (Blueprint $t) {
            $t->dateTime('received_at')->nullable()->after('created_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('messages', 'received_at')) return;
        Schema::table('messages', function (Blueprint $t) {
            $t->dropColumn('received_at');
        });
    }
};
