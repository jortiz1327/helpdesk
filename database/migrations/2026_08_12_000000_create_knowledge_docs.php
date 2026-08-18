<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos de la base de conocimiento de la IA. Son INTERNOS (no los ve el
 * cliente, a diferencia de las FAQs del portal): manuales, tarifas, guías… El
 * agente de IA se apoya en su texto para responder. Se guarda el TEXTO extraído
 * (es lo que lee la IA); el binario no hace falta conservarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('knowledge_docs', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('filename', 255)->nullable();  // nombre del fichero de origen
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);   // bytes del original
            $table->longText('content');                   // texto extraído / pegado
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('knowledge_docs');
    }
};
