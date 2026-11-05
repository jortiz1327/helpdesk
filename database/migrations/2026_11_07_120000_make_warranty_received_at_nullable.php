<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La fecha de recepción deja de ser obligatoria: la garantía se abre a menudo antes de
 * que el cliente haya enviado las etiquetas, y se pone cuando llegan al almacén.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $t) {
            $t->date('received_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Las que no tengan fecha toman la de alta para poder volver a NOT NULL.
        \Illuminate\Support\Facades\DB::table('warranties')->whereNull('received_at')->update(['received_at' => \Illuminate\Support\Facades\DB::raw('DATE(created_at)')]);
        Schema::table('warranties', function (Blueprint $t) {
            $t->date('received_at')->nullable(false)->change();
        });
    }
};
