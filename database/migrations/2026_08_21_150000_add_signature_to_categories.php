<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Firma por DEPARTAMENTO (categoría). Se anexa a las respuestas por correo del ticket,
 * antes del pie de empresa global. Admite {{agente}} y {{departamento}}. Distinta de
 * `email_footer` (pie legal/común de la empresa), que sigue igual.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->text('signature')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('ticket_categories', function (Blueprint $table) {
            $table->dropColumn('signature');
        });
    }
};
