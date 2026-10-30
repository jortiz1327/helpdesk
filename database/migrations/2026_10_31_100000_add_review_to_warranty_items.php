<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Revisión de cada dispositivo de una garantía: su solución (correcta, batería baja,
 * defectuosa…), lo que la acompaña según cuál sea, y las fotos que algunas piden.
 * Una foto es un fichero subido aquí o una copia de una imagen que el cliente mandó en
 * el ticket (source dice cuál).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranty_items', function (Blueprint $t) {
            $t->string('resolution', 20)->default('pendiente')->after('warnings');
            $t->text('resolution_note')->nullable()->after('resolution');
            $t->json('broken_cases')->nullable()->after('resolution_note');   // dañada por el cliente: motivos
            $t->boolean('battery_changed')->default(false)->after('broken_cases');
            $t->string('zkong_decision', 12)->nullable()->after('battery_changed'); // defectuosa: decidir | enviar | no_enviar
            $t->string('zkong_issue', 500)->nullable()->after('zkong_decision');    // el fallo, para el RMA de ZKONG
            $t->dateTime('reviewed_at')->nullable()->after('zkong_issue');
            $t->foreignId('reviewed_by')->nullable()->after('reviewed_at')->constrained('users')->nullOnDelete();
            $t->index('resolution');
        });

        Schema::create('warranty_item_photos', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warranty_item_id')->constrained('warranty_items')->cascadeOnDelete();
            $t->string('type', 20);                       // front | back_batteries | back_no_batteries | back
            $t->string('path', 255);                      // copia propia en el disco local
            $t->string('source', 20)->nullable();         // del ticket: «a:ID» adjunto, «m:ID» WhatsApp
            $t->string('name', 190)->nullable();
            $t->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('created_at')->useCurrent();
            $t->unique(['warranty_item_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_item_photos');
        Schema::table('warranty_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('reviewed_by');
            $t->dropIndex(['resolution']);
            $t->dropColumn(['resolution', 'resolution_note', 'broken_cases', 'battery_changed', 'zkong_decision', 'zkong_issue', 'reviewed_at']);
        });
    }
};
