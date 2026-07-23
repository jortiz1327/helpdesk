<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('phonebooks', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('phonebook_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('phonebook_id')->index();
            $table->string('wa_id', 32);
            $table->string('name', 160)->nullable();
            $table->dateTime('created_at')->useCurrent();
            $table->unique(['phonebook_id', 'wa_id'], 'uniq_pb_wa');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phonebook_contacts');
        Schema::dropIfExists('phonebooks');
    }
};
