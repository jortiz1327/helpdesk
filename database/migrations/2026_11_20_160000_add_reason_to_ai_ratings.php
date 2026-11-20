<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** 👎 rápido con motivo: una etiqueta de un clic (tema, dato, derivar, responder, inventó, tono) además del texto libre. Solo se añade. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_suggestions', fn (Blueprint $t) => $t->string('reason', 20)->nullable()->after('correction'));
        Schema::table('ai_rating_events', fn (Blueprint $t) => $t->string('reason', 20)->nullable()->after('correction'));
    }

    public function down(): void
    {
        Schema::table('ai_suggestions', fn (Blueprint $t) => $t->dropColumn('reason'));
        Schema::table('ai_rating_events', fn (Blueprint $t) => $t->dropColumn('reason'));
    }
};