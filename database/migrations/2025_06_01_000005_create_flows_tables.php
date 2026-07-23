<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flows', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160)->default('Sin título');
            $table->tinyInteger('active')->default(0);
            $table->longText('graph')->nullable();
            $table->timestamps();
        });

        Schema::create('flow_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('flow_id');
            $table->string('current_node', 64)->nullable();
            $table->longText('variables')->nullable();
            $table->string('status', 16)->default('active');
            $table->dateTime('resume_at')->nullable()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        Schema::create('flow_responses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('contact_id')->index();
            $table->unsignedBigInteger('flow_id')->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('variable', 64);
            $table->text('value')->nullable();
            $table->dateTime('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flow_responses');
        Schema::dropIfExists('flow_sessions');
        Schema::dropIfExists('flows');
    }
};
