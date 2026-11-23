<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Directores de marca (y usuarios de varios hoteles de una misma marca): el contacto se enlaza a la MARCA en vez de a una sede.
// Comparte con sede_id la confirmación (sede_verificada_at / sede_verificada_por) y caduca igual, a los 12 meses.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->unsignedBigInteger('marca_id')->nullable()->index()->after('sede_id');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropIndex(['marca_id']);
            $t->dropColumn('marca_id');
        });
    }
};
