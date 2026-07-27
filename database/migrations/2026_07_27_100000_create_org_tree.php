<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * ORGANIZACIÓN de clientes en 3 niveles:  Grupo → Marca → Sede.
 * Ejemplo: Grupo Barceló → marcas Allegro/Occidental → cada marca sus sedes (hoteles).
 * El contacto pertenece a una SEDE; el ticket, al colgar del contacto, sube solo por
 * la cadena (sede → marca → grupo), así se puede ver «todo lo de Allegro» o del grupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grupos', function (Blueprint $t) {
            $t->id();
            $t->string('name', 160);
            $t->string('color', 9)->nullable();      // para pintarlo en la interfaz
            $t->text('note')->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('marcas', function (Blueprint $t) {
            $t->id();
            $t->foreignId('grupo_id')->constrained('grupos')->cascadeOnUpdate()->restrictOnDelete();
            $t->string('name', 160);
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index('grupo_id');
        });

        Schema::create('sedes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('marca_id')->constrained('marcas')->cascadeOnUpdate()->restrictOnDelete();
            $t->string('name', 160);
            $t->string('city', 120)->nullable();
            $t->string('address', 200)->nullable();
            $t->boolean('active')->default(true);
            $t->timestamps();
            $t->index('marca_id');
        });

        // El contacto pertenece a una sede (opcional). Si se borra la sede, se queda sin ella.
        Schema::table('contacts', function (Blueprint $t) {
            $t->foreignId('sede_id')->nullable()->after('assigned_to')->constrained('sedes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('sede_id');
        });
        Schema::dropIfExists('sedes');
        Schema::dropIfExists('marcas');
        Schema::dropIfExists('grupos');
    }
};
