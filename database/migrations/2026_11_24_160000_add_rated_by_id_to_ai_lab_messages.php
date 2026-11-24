<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/* Quién valoró o corrigió cada respuesta (id), para que un agente solo pueda retirar sus propias correcciones. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_lab_messages', function (Blueprint $t) {
            $t->unsignedBigInteger('rated_by_id')->nullable()->after('rated_by_name');
        });
    }

    public function down(): void
    {
        Schema::table('ai_lab_messages', function (Blueprint $t) {
            $t->dropColumn('rated_by_id');
        });
    }
};
