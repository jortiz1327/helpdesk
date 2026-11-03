<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Protocolos y guías de garantías: documentos operativos (PDF, Word…) por tipo, idioma y
 * ámbito. Sin categorías ni clientes vale para todas; si no, para esas categorías o los
 * clientes cuyo nombre contiene el texto (como los pasos por tipo de cliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_guides', function (Blueprint $t) {
            $t->id();
            $t->string('title', 160);
            $t->text('description')->nullable();
            $t->string('kind', 20);
            $t->string('language', 2)->default('es');
            $t->json('categories')->nullable();
            $t->json('clients')->nullable();
            $t->string('version', 20)->nullable();
            $t->string('keywords', 500)->nullable();
            $t->string('path', 255);
            $t->string('file_name', 191);
            $t->string('mime', 100);
            $t->unsignedInteger('size')->default(0);
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_guides');
    }
};
