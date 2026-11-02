<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Plazos de garantía, texto para Finanzas y correo de resolución por bloques (todo en
 * Configuración). La instalación de cada dispositivo es su alta en ZKONG; la garantía
 * puede indicar una fecha de instalación que manda sobre todas.
 */
return new class extends Migration
{
    private const BODY_ANTES = "Hola {cliente}:\n\nHemos terminado de revisar los dispositivos de la garantía {codigo} ({proyecto}).\n\n{resumen}\n\nTe adjuntamos el informe de resolución con el detalle de cada dispositivo{documentos}.\n\nUn saludo,\n{agente}";

    public function up(): void
    {
        Schema::table('warranty_items', fn (Blueprint $t) => $t->date('installed_at')->nullable()->after('last_commu_at'));
        Schema::table('warranties', fn (Blueprint $t) => $t->date('installed_at')->nullable()->after('received_at'));

        // Las que ya están: su primera alta conocida en ZKONG.
        foreach (DB::table('device_sightings')->whereNotNull('added_at')->groupBy('code')->selectRaw('code, min(added_at) as f')->get() as $s) {
            DB::table('warranty_items')->where('code', $s->code)->update(['installed_at' => substr((string) $s->f, 0, 10)]);
        }

        $ahora = now();
        $poner = fn ($k, $v) => DB::table('warranty_settings')->insertOrIgnore(['key' => $k, 'value' => json_encode($v), 'created_at' => $ahora, 'updated_at' => $ahora]);
        $poner('terms', \App\Services\WarrantyTerms::DEFAULTS);
        $poner('finance', \App\Services\WarrantyDocuments::FINANCE_DEFAULTS);
        $poner('resolution_blocks', \App\Services\WarrantyDocuments::BLOCK_DEFAULTS);

        // El correo de serie pasa a llevar el detalle por bloques; uno ya cambiado se respeta.
        $e = json_decode((string) DB::table('warranty_settings')->where('key', 'resolution_email')->value('value'), true);
        if (($e['body'] ?? null) === self::BODY_ANTES) {
            $e['body'] = \App\Services\WarrantyDocuments::BODY_DEFAULT;
            DB::table('warranty_settings')->where('key', 'resolution_email')->update(['value' => json_encode($e), 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        DB::table('warranty_settings')->whereIn('key', ['terms', 'finance', 'resolution_blocks'])->delete();
        Schema::table('warranties', fn (Blueprint $t) => $t->dropColumn('installed_at'));
        Schema::table('warranty_items', fn (Blueprint $t) => $t->dropColumn('installed_at'));
    }
};
