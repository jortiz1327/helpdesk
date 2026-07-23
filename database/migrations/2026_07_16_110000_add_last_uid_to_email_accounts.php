<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastreo incremental del buzón por UID de IMAP. Guardamos el último UID
 * procesado; así el sondeo es independiente del flag leído/no leído y no
 * reimporta correos ya convertidos en ticket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->unsignedBigInteger('last_uid')->nullable()->after('last_check_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_accounts', function (Blueprint $table) {
            $table->dropColumn('last_uid');
        });
    }
};
