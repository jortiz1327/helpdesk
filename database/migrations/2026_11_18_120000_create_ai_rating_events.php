<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de valoraciones de la IA (solo se añade, nunca se pisa): cada vez que un agente valora una
 * sugerencia (👍/👎 con su corrección) o un responsable la valida/rechaza, queda una fila con quién, cuándo,
 * qué había sugerido la IA en ese momento y qué dijo la persona. Lo que se envió al final al cliente se
 * lee del propio ticket al consultar el historial.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_rating_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('suggestion_id');
            $t->unsignedBigInteger('ticket_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('type', 10);                                 // rating (agente) | review (responsable)
            $t->string('rating', 4)->nullable();                    // up | down (solo en rating)
            $t->text('correction')->nullable();
            $t->string('correction_status', 10)->nullable();        // pending | validated | rejected (estado tras el evento)
            $t->longText('ai_body')->nullable();                    // lo que la IA había sugerido en ese momento
            $t->timestamp('created_at')->nullable();
            $t->index('suggestion_id');
            $t->index(['type', 'created_at']);
        });

        // Lo que ya estaba valorado entra como primer apunte del historial.
        foreach (DB::table('ai_suggestions')->whereNotNull('rating')->get() as $s) {
            DB::table('ai_rating_events')->insert([
                'suggestion_id' => $s->id, 'ticket_id' => $s->ticket_id, 'user_id' => $s->rated_by, 'type' => 'rating', 'rating' => $s->rating,
                'correction' => $s->correction, 'correction_status' => $s->rating === 'down' ? $s->correction_status : null,
                'ai_body' => $s->body, 'created_at' => $s->rated_at ?? $s->updated_at,
            ]);
            if ($s->rating === 'down' && $s->correction_reviewed_by && $s->correction_reviewed_at) {
                DB::table('ai_rating_events')->insert([
                    'suggestion_id' => $s->id, 'ticket_id' => $s->ticket_id, 'user_id' => $s->correction_reviewed_by, 'type' => 'review', 'rating' => null,
                    'correction' => $s->correction, 'correction_status' => $s->correction_status, 'ai_body' => $s->body, 'created_at' => $s->correction_reviewed_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_rating_events');
    }
};
