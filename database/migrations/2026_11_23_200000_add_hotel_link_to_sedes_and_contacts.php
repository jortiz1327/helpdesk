<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Memoria de «de qué hotel es este contacto»: la sede se enlaza con el hotel de AdminHoteles y el contacto guarda cuándo y cómo se confirmó.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('sedes', function (Blueprint $t) {
            $t->string('admin_hotel_id', 64)->nullable()->index()->after('address');
            $t->string('origen', 20)->nullable()->after('admin_hotel_id');   // 'adminhoteles' = creada sola al verificar un correo
        });
        Schema::table('contacts', function (Blueprint $t) {
            $t->timestamp('sede_verificada_at')->nullable()->after('sede_id');
            $t->string('sede_verificada_por', 12)->nullable()->after('sede_verificada_at');   // 'correo' | 'agente'
        });
    }

    public function down(): void
    {
        Schema::table('contacts', fn (Blueprint $t) => $t->dropColumn(['sede_verificada_at', 'sede_verificada_por']));
        Schema::table('sedes', function (Blueprint $t) {
            $t->dropIndex(['admin_hotel_id']);
            $t->dropColumn(['admin_hotel_id', 'origen']);
        });
    }
};
