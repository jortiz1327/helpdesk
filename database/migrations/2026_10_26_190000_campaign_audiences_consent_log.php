<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * CAMPAÑAS (solo Campañas, nada del Helpdesk):
 * - Audiencias: a quién va una campaña como REGLA (segmento de interés + tipo, varias
 *   etiquetas, provincia, todos…), guardables con nombre y que se recalculan al enviar.
 *   La campaña guarda su regla y una descripción legible para el historial.
 * - Registro de consentimientos y bajas (RGPD): cada alta/baja/consentimiento es una
 *   línea con fecha y origen, para poder demostrarlo. Hasta hoy solo había la ÚLTIMA
 *   fecha en la ficha: esas se vuelcan como origen «previo».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            if (!Schema::hasColumn('campaigns', 'audience')) $t->text('audience')->nullable();
            if (!Schema::hasColumn('campaigns', 'audience_desc')) $t->string('audience_desc', 255)->nullable();
        });

        if (!Schema::hasTable('campaign_audiences')) {
            Schema::create('campaign_audiences', function (Blueprint $t) {
                $t->id();
                $t->string('name', 80);
                $t->text('rules');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (!Schema::hasTable('consent_log')) {
            Schema::create('consent_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('contact_id')->index();
                $t->string('event', 20);      // consent | optout | optin
                $t->string('source', 20);     // whatsapp | email | agente | previo
                $t->unsignedBigInteger('user_id')->nullable();
                $t->dateTime('created_at')->nullable()->index();
            });

            // Lo que ya había en las fichas (solo la última fecha conocida).
            $ahora = now();
            foreach (DB::table('contacts')->where('opted_out', 1)->get(['id', 'opted_out_at']) as $c) {
                DB::table('consent_log')->insert(['contact_id' => $c->id, 'event' => 'optout', 'source' => 'previo', 'created_at' => $c->opted_out_at ?? $ahora]);
            }
            foreach (DB::table('contacts')->where('consent', 2)->get(['id', 'consent_at']) as $c) {
                DB::table('consent_log')->insert(['contact_id' => $c->id, 'event' => 'consent', 'source' => 'previo', 'created_at' => $c->consent_at ?? $ahora]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('consent_log');
        Schema::dropIfExists('campaign_audiences');
        Schema::table('campaigns', function (Blueprint $t) {
            if (Schema::hasColumn('campaigns', 'audience')) $t->dropColumn('audience');
            if (Schema::hasColumn('campaigns', 'audience_desc')) $t->dropColumn('audience_desc');
        });
    }
};
