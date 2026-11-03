<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos de alertas de garantías: qué alerta crítica de cada garantía ya se ha avisado
 * (para no repetirla mientras siga) y la garantía a la que lleva un aviso de la campana.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_alert_notices', function (Blueprint $t) {
            $t->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $t->string('alert_key', 30);
            $t->timestamp('notified_at')->nullable();
            $t->primary(['warranty_id', 'alert_key']);
        });
        Schema::table('notifications', function (Blueprint $t) {
            $t->foreignId('warranty_id')->nullable()->after('ticket_id')->constrained('warranties')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $t) {
            $t->dropConstrainedForeignId('warranty_id');
        });
        Schema::dropIfExists('warranty_alert_notices');
    }
};
