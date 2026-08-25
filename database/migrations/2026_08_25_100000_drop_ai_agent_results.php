<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elimina la tabla `ai_agent_results`: era el receptor experimental de un agente externo
 * (ChatGPT/Workspace Agent) que guardaba resultados pero nunca los pintaba en el ticket.
 * Con ClaudeBrain funcionando, sobraba. Se retira para reducir superficie pública.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('ai_agent_results');
        // Limpia el secreto autogenerado del webhook (ya no se usa).
        \App\Models\Setting::query()->where('key', 'ai_webhook_secret')->delete();
    }

    public function down(): void
    {
        // Se recrea vacía por si se revierte (estructura mínima del experimento original).
        Schema::create('ai_agent_results', function (Blueprint $table) {
            $table->id();
            $table->string('ref', 100)->nullable()->index();
            $table->longText('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
};
