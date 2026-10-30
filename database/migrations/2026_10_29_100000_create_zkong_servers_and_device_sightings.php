<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Centro de dispositivos (área Gestión de garantías).
 *
 * - zkong_servers: una fila por servidor ZKONG (cuenta de administrador). La contraseña y
 *   el token van cifrados con la APP_KEY (cast `encrypted`). Se rellenan desde la pantalla
 *   «Servidores ZKONG»: no se copian de la app de garantías (allí van con otra clave).
 * - device_sightings: el historial de cada dispositivo. Los servidores no guardan historia;
 *   aquí se abre una fila nueva solo cuando cambia algo que importa (servidor, tienda,
 *   modelo, firmware, antena). Si no cambia nada, se actualiza la última.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zkong_servers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60);
            $t->string('base_url', 190);
            $t->string('username', 120);
            $t->text('password');
            $t->text('token')->nullable();
            $t->unsignedBigInteger('merchant_id')->nullable();
            $t->unsignedBigInteger('agency_id')->nullable();
            $t->timestamp('token_updated_at')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('position')->default(0);
            $t->timestamps();
        });

        Schema::create('device_sightings', function (Blueprint $t) {
            $t->id();
            $t->string('code', 40);
            $t->string('kind', 10);                       // esl | ap
            $t->string('server', 60)->nullable();
            $t->unsignedBigInteger('store_id')->nullable();
            $t->string('store_name', 120)->nullable();
            $t->string('merchant_name', 120)->nullable();
            $t->string('model', 60)->nullable();
            $t->string('firmware', 60)->nullable();
            $t->string('ap_mac', 40)->nullable();
            $t->boolean('online')->default(false);
            $t->timestamp('last_commu_at')->nullable();
            $t->timestamp('added_at')->nullable();
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('seen_at')->nullable();
            $t->foreignId('seen_by')->nullable()->constrained('users')->nullOnDelete();
            $t->index(['code', 'seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sightings');
        Schema::dropIfExists('zkong_servers');
    }
};
