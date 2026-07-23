<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESTINATARIOS de cada plantilla (equivale a «Configuración de alertas y avisos»
 * de osTicket): además del destinatario natural, se puede avisar al equipo del
 * área o a los administradores.
 *
 *   client   → el contacto del ticket
 *   agent    → el agente asignado
 *   category → los agentes del área del ticket (los «miembros del departamento»)
 *   admins   → quienes pueden configurar el soporte
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->json('recipients')->nullable()->after('active');
        });

        // Los de siempre: se conserva el comportamiento que ya había.
        foreach ([
            'ticket_created'  => ['client' => true,  'agent' => false, 'category' => false, 'admins' => false],
            'ticket_closed'   => ['client' => true,  'agent' => false, 'category' => false, 'admins' => false],
            'ticket_assigned' => ['client' => false, 'agent' => true,  'category' => false, 'admins' => false],
        ] as $key => $dest) {
            DB::table('email_templates')->where('key', $key)->update(['recipients' => json_encode($dest)]);
        }
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('recipients');
        });
    }
};
