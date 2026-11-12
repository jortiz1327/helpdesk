<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Base de conocimiento de la IA: texto ya limpio (sin datos personales ni notas internas)
 * sacado de tickets resueltos y de manuales. Es una COPIA reconstruible: se puede vaciar y
 * volver a generar con `php artisan ai:indexar --rehacer` sin tocar los tickets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_documents', function (Blueprint $t) {
            $t->id();
            $t->string('source_type', 12);                 // ticket | manual
            $t->string('source_id', 60);                   // id del ticket, o «clave#trozo» del manual
            $t->string('title', 255);
            $t->mediumText('content');
            $t->unsignedTinyInteger('quality')->nullable(); // valoración del cliente (1-5), si la hubo
            $t->timestamp('indexed_at')->useCurrent();
            $t->unique(['source_type', 'source_id']);
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('CREATE FULLTEXT INDEX ai_documents_ft ON ai_documents (title, content)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_documents');
    }
};
