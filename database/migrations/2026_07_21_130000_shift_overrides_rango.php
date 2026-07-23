<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Las sustituciones pasan de UN DÍA a un PERIODO.
 *
 * En la práctica una sustitución rara vez es de un día suelto: alguien coge tres
 * días, o la semana entera. Guardarlo como un rango deja una sola entrada
 * («Juan cubre del 20 al 24») en vez de cinco sueltas que hay que quitar una a una.
 *
 * Se cae el unique(date,shift): un rango no se puede validar con un índice, los
 * solapes se recortan en ShiftsController::override().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('shift_overrides', 'date')) {
            Schema::table('shift_overrides', function (Blueprint $table) {
                $table->dropUnique(['date', 'shift']);
                $table->renameColumn('date', 'date_from');
            });
        }

        // Nullable primero: con filas ya guardadas, una columna de fecha obligatoria
        // sin valor por defecto revienta en modo estricto.
        Schema::table('shift_overrides', function (Blueprint $table) {
            $table->date('date_to')->nullable()->after('date_from');
        });

        // Lo que ya había eran días sueltos: un rango que empieza y acaba el mismo día.
        DB::statement('UPDATE shift_overrides SET date_to = date_from');

        Schema::table('shift_overrides', function (Blueprint $table) {
            $table->date('date_to')->nullable(false)->change();
        });

        Schema::table('shift_overrides', function (Blueprint $table) {
            $table->index(['shift', 'date_from', 'date_to'], 'so_busqueda');
        });
    }

    public function down(): void
    {
        Schema::table('shift_overrides', function (Blueprint $table) {
            $table->dropIndex('so_busqueda');
            $table->dropColumn('date_to');
            $table->renameColumn('date_from', 'date');
        });

        Schema::table('shift_overrides', function (Blueprint $table) {
            $table->unique(['date', 'shift']);
        });
    }
};
