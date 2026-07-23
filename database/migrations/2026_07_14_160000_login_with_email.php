<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El EMAIL pasa a ser el identificador de acceso del usuario.
 *
 * Se elimina `username`: mantener dos identificadores cuando solo uno sirve para
 * entrar es una fuente seguray de bugs (¿por cuál busco? ¿cuál muestro? ¿cuál
 * debe ser único?). El email es además lo que necesita el canal correo.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Red de seguridad: si alguien no tuviera email, se le genera uno a partir
        // de su usuario para no dejarlo fuera de la aplicación.
        foreach (DB::table('users')->whereNull('email')->orWhere('email', '')->get(['id', 'username']) as $u) {
            DB::table('users')->where('id', $u->id)->update(['email' => $u->username . '@local']);
        }

        DB::statement('ALTER TABLE users MODIFY email VARCHAR(150) NOT NULL');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 60)->nullable()->after('id');
        });

        // Reconstruye el usuario a partir de la parte local del email
        foreach (DB::table('users')->get(['id', 'email']) as $u) {
            DB::table('users')->where('id', $u->id)->update([
                'username' => strtok((string) $u->email, '@'),
            ]);
        }

        DB::statement('ALTER TABLE users MODIFY email VARCHAR(150) NULL');
    }
};
