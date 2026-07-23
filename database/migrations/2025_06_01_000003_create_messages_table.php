<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id')->index();
            $table->string('wa_id', 32);
            $table->enum('direction', ['in', 'out']);
            $table->string('type', 24)->default('text');
            $table->text('body')->nullable();
            $table->text('media_url')->nullable();
            $table->string('media_mime', 64)->nullable();
            $table->string('wamid', 128)->nullable()->index();
            $table->string('status', 24)->default('sent');
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->longText('payload')->nullable();
            $table->dateTime('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
