<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FUSIONAR TICKETS: el mismo cliente abre dos veces lo mismo y hay que juntarlo.
 *
 * El ticket absorbido NO se borra, y no es por prudencia: los correos vuelven a su
 * ticket por el código del asunto (`TK-2607-0017`, ver MailService::ticketByCode).
 * Si se borrara, la respuesta del cliente al hilo antiguo abriría un ticket nuevo
 * —justo el problema que la fusión venía a resolver—. Se queda como REDIRECCIÓN:
 * `merged_into_id` apunta al que sobrevive y el correo entra ahí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->foreignId('merged_into_id')->nullable()->after('closed_at')
                ->constrained('tickets')->nullOnDelete();
            $t->timestamp('merged_at')->nullable()->after('merged_into_id');
        });
    }

    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $t) {
            $t->dropConstrainedForeignId('merged_into_id');
            $t->dropColumn('merged_at');
        });
    }
};
