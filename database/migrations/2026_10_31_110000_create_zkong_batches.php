<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Remesas a ZKONG (RMA). Las etiquetas revisadas con «Enviar a ZKONG» esperan en la
 * bandeja (zkong_queued_at) hasta que entran en una remesa; la remesa se manda a ZKONG
 * con su Excel y sus fotos, y la respuesta de ZKONG se apunta por etiqueta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zkong_batches', function (Blueprint $t) {
            $t->id();
            $t->string('code', 20)->unique();                    // R-2026-001
            $t->string('status', 12)->default('borrador');       // borrador | enviada | respondida | cerrada
            $t->text('note')->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->dateTime('closed_at')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index('status');
        });

        Schema::table('warranty_items', function (Blueprint $t) {
            $t->dateTime('zkong_queued_at')->nullable()->after('zkong_issue');      // entrada en la bandeja
            $t->foreignId('zkong_batch_id')->nullable()->after('zkong_queued_at')->constrained('zkong_batches')->nullOnDelete();
            $t->string('zkong_result', 12)->nullable()->after('zkong_batch_id');    // reposicion | abono | rechazada
            $t->string('zkong_result_note', 500)->nullable()->after('zkong_result');
        });

        // Las ya revisadas con «Enviar a ZKONG» entran en la bandeja con la fecha de revisión.
        DB::table('warranty_items')->where('zkong_decision', 'enviar')
            ->update(['zkong_queued_at' => DB::raw('coalesce(reviewed_at, updated_at)')]);
    }

    public function down(): void
    {
        Schema::table('warranty_items', function (Blueprint $t) {
            $t->dropConstrainedForeignId('zkong_batch_id');
            $t->dropColumn(['zkong_queued_at', 'zkong_result', 'zkong_result_note']);
        });
        Schema::dropIfExists('zkong_batches');
    }
};
