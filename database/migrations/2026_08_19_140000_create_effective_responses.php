<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Memoria de RESPUESTAS EFECTIVAS: respuestas reales que un agente marcó como buenas
 * (botón ⭐ en el mensaje enviado). Se guardan con la categoría y las palabras clave del
 * caso, y se REUTILIZAN en tickets parecidos: se sugieren al agente en el compositor y
 * se le pasan a la IA como contexto extra. Distinto de `canned_responses` (catálogo
 * manual): esto se aprende de envíos reales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('effective_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('ticket_id')->nullable()->index();   // ticket de origen
            $table->unsignedBigInteger('category_id')->nullable()->index(); // categoría del caso
            $table->string('title', 180)->nullable();                       // etiqueta corta (asunto)
            $table->longText('body');                                       // la respuesta (para reinsertar)
            $table->text('keywords')->nullable();                           // texto plano para buscar por parecido
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedInteger('uses')->default(0);                    // cuántas veces se ha reutilizado
            $table->timestamp('created_at')->nullable()->index();

            // Búsqueda por parecido (asunto + consulta del cliente).
            if (\Illuminate\Support\Facades\DB::getDriverName() === 'mysql') {
                $table->fullText('keywords', 'efres_kw_ft');   // FULLTEXT solo en MySQL (tests con sqlite)
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('effective_responses');
    }
};
