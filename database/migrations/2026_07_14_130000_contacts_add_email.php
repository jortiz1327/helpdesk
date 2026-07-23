<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * En el diseño de referencia el cliente se identifica por EMAIL (los tickets del
 * portal y del canal correo no tienen por qué traer teléfono).
 *  - contacts.email: nuevo.
 *  - contacts.wa_id: pasa a ser NULLABLE (un ticket web/email puede no tener móvil).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('email', 150)->nullable()->after('name');
            $table->index('email');
        });

        // wa_id deja de ser obligatorio (sigue siendo único; MySQL admite varios NULL)
        DB::statement('ALTER TABLE contacts MODIFY wa_id VARCHAR(20) NULL');
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex(['email']);
            $table->dropColumn('email');
        });
        DB::statement('ALTER TABLE contacts MODIFY wa_id VARCHAR(20) NOT NULL');
    }
};
