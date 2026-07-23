<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title', 200);
            $table->string('template_name', 160);
            $table->string('language', 16)->default('es');
            $table->longText('components')->nullable();
            $table->unsignedBigInteger('phonebook_id')->nullable();
            $table->unsignedBigInteger('label_id')->nullable();
            $table->string('status', 16)->default('draft')->index();
            $table->dateTime('scheduled_at')->nullable()->index();
            $table->integer('total')->default(0);
            $table->integer('sent')->default(0);
            $table->integer('failed')->default(0);
            $table->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('campaign_id')->index();
            $table->string('wa_id', 32);
            $table->string('name', 160)->nullable();
            $table->string('status', 16)->default('pending')->index();
            $table->string('wamid', 128)->nullable();
            $table->text('error')->nullable();
            $table->dateTime('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
    }
};
