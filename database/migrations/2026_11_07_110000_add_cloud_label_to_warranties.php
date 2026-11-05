<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Etiqueta de almacén de la garantía: la etiqueta de la tienda del almacén (Cloud) que
 * enseña su pegatina. Una etiqueta solo está con una garantía a la vez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $t) {
            $t->string('cloud_label', 40)->nullable()->index();
            $t->timestamp('cloud_label_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $t) {
            $t->dropColumn(['cloud_label', 'cloud_label_at']);
        });
    }
};
