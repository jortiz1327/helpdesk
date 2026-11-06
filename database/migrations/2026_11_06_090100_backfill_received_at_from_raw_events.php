<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Rellena messages.received_at de los WhatsApp ya guardados: cada aviso de WhatsApp queda
 * en wa_raw_events al llegar, con su hora, así que el primero que trae un mensaje dice
 * cuándo nos llegó. Los del historial importado no tienen aviso y se quedan sin hora (no
 * hace falta: sin hora de llegada no se marca ningún retraso). Solo rellena huecos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $llegada = [];   // wamid → hora del primer aviso que lo trajo
        DB::table('wa_raw_events')->whereIn('field', ['messages', 'smb_message_echoes'])
            ->orderBy('id')->select(['id', 'payload', 'created_at'])
            ->chunkById(500, function ($eventos) use (&$llegada) {
                foreach ($eventos as $e) {
                    $v = json_decode((string) $e->payload, true) ?: [];
                    foreach (array_merge($v['messages'] ?? [], $v['message_echoes'] ?? []) as $m) {
                        $id = (string) ($m['id'] ?? '');
                        if ($id !== '' && ! isset($llegada[$id]) && $e->created_at) $llegada[$id] = $e->created_at;
                    }
                }
            });

        foreach (array_chunk($llegada, 500, true) as $trozo) {
            foreach ($trozo as $wamid => $cuando) {
                DB::table('messages')->where('wamid', $wamid)->whereNull('received_at')->update(['received_at' => $cuando]);
            }
        }
    }

    public function down(): void
    {
        // Nada: la columna la quita la migración anterior.
    }
};
