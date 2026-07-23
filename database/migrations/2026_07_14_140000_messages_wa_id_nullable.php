<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `messages` venía de la app de WhatsApp y exigía `wa_id` (el móvil).
 * Al pasar el foco a los canales WEB y CORREO, un mensaje puede no tener
 * teléfono: el remitente se identifica por email o es un agente interno.
 * El canal real ya lo indica la columna `channel`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE messages MODIFY wa_id VARCHAR(20) NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE messages SET wa_id = '' WHERE wa_id IS NULL");
        DB::statement('ALTER TABLE messages MODIFY wa_id VARCHAR(20) NOT NULL');
    }
};
