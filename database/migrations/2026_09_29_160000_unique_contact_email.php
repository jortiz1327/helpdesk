<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * ÍNDICE ÚNICO en contacts.email: garantiza que no haya dos contactos con el mismo
 * correo, ni siquiera ante una condición de carrera (dos altas a la vez con el mismo
 * correo). La colación es _ci, así que «Info@x» y «info@x» cuentan como iguales. Los
 * contactos sin correo (WhatsApp) tienen email NULL, y un índice único admite varios
 * NULL, así que no les afecta. Se sustituye el índice normal anterior por el único.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            try { $table->dropIndex('contacts_email_index'); } catch (\Throwable $e) { /* no existía */ }
        });
        Schema::table('contacts', function (Blueprint $table) {
            $table->unique('email', 'contacts_email_unique');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            try { $table->dropUnique('contacts_email_unique'); } catch (\Throwable $e) { /* */ }
            $table->index('email', 'contacts_email_index');
        });
    }
};
