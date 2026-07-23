<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('labels', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('color', 16)->default('#00a884');
            $table->integer('position')->default(0);
        });

        Schema::create('contact_labels', function (Blueprint $table) {
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('label_id');
            $table->primary(['contact_id', 'label_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_labels');
        Schema::dropIfExists('labels');
    }
};
