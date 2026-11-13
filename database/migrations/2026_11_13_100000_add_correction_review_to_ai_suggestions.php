<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una corrección de un agente no es una verdad permanente: hasta que un responsable la valida
 * (pending → validated) NO llega a la IA. Una rechazada (rejected) tampoco.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_suggestions', function (Blueprint $t) {
            $t->string('correction_status', 10)->default('pending');   // pending | validated | rejected
            $t->unsignedBigInteger('correction_reviewed_by')->nullable();
            $t->timestamp('correction_reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_suggestions', function (Blueprint $t) {
            $t->dropColumn(['correction_status', 'correction_reviewed_by', 'correction_reviewed_at']);
        });
    }
};
