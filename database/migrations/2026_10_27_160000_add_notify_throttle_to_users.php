<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cada cuántos minutos, como mucho, se avisa a un agente de nuevos mensajes del MISMO
 * ticket, por canal de entrada: {"web":5,"email":1,"whatsapp":10}. null = el mínimo
 * que fije el admin (ver NotifyThrottle).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notify_throttle')->nullable()->after('notify_reply');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_throttle');
        });
    }
};
