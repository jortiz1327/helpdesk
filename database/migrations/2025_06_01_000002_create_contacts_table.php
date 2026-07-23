<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('wa_id', 32)->unique();
            $table->string('name', 160)->nullable();
            $table->text('last_message')->nullable();
            $table->dateTime('last_time')->nullable()->index();
            $table->integer('unread')->default(0);
            $table->text('note')->nullable();
            $table->tinyInteger('opted_out')->default(0);
            $table->dateTime('opted_out_at')->nullable();
            // 0 = pendiente de preguntar · 1 = preguntado (esperando) · 2 = aceptado/eximido
            $table->tinyInteger('consent')->default(0);
            $table->dateTime('consent_at')->nullable();
            $table->tinyInteger('bot_off')->default(0);
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
