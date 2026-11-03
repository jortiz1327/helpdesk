<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Quién ha visto (o rechazado) el tour guiado de garantías: se ofrece una sola vez. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_tour_views', function (Blueprint $t) {
            $t->foreignId('user_id')->primary()->constrained('users')->cascadeOnDelete();
            $t->timestamp('seen_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_tour_views');
    }
};
