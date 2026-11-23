<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Resumen de un ticket para quien lo coge (hilos largos). Uno por ticket y último mensaje; se reutiliza mientras no haya mensajes nuevos.
return new class extends Migration {
    public function up(): void
    {
        Schema::create('ai_summaries', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('ticket_id')->index();
            $t->unsignedBigInteger('last_message_id');
            $t->text('body');
            $t->string('model', 80)->nullable();
            $t->unsignedInteger('input_tokens')->nullable();
            $t->unsignedInteger('output_tokens')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_summaries');
    }
};
