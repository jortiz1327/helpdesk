<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Chat de pruebas de la IA dentro de Ajustes (agentes de soporte): cada conversación, cada mensaje y cada
 * corrección queda con el nombre de quien la hizo, y una corrección ya guardada se puede retirar de la memoria
 * (y restaurar) sin borrar la conversación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_lab_chats', function (Blueprint $t) {
            $t->unsignedBigInteger('author_id')->nullable()->after('contact_email');
            $t->string('author_name', 120)->nullable()->after('author_id');
        });
        Schema::table('ai_lab_messages', function (Blueprint $t) {
            $t->string('author_name', 120)->nullable()->after('body');          // quien escribió el mensaje del «cliente»
            $t->string('rated_by_name', 120)->nullable()->after('correction');  // quien valoró o corrigió la respuesta
            $t->timestamp('rated_at')->nullable()->after('rated_by_name');
            $t->timestamp('retired_at')->nullable()->after('rated_at');         // corrección retirada de la memoria
            $t->string('retired_by_name', 120)->nullable()->after('retired_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_lab_messages', function (Blueprint $t) {
            $t->dropColumn(['author_name', 'rated_by_name', 'rated_at', 'retired_at', 'retired_by_name']);
        });
        Schema::table('ai_lab_chats', function (Blueprint $t) {
            $t->dropColumn(['author_id', 'author_name']);
        });
    }
};
