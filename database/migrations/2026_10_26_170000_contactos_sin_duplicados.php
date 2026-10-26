<?php

use App\Services\ContactMergeService;
use App\Support\Telefono;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * UN CLIENTE = UNA FICHA.
 *  1) Pares que alguien marcó como «no son la misma persona» (para no volver a sugerirlos).
 *  2) Teléfonos al formato único (con prefijo: 34612345678). Si al normalizar dos fichas
 *     quedan con el mismo número, son la misma persona y se FUSIONAN (manda la que tiene
 *     correo; si no, la más antigua). Va antes de importar el WhatsApp de soporte, que trae
 *     los números siempre con prefijo: así no se crea una segunda ficha para nadie.
 *  3) Correos en minúsculas y sin espacios.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('contact_duplicate_ignores')) {
            Schema::create('contact_duplicate_ignores', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('contact_a');   // siempre el id menor
                $t->unsignedBigInteger('contact_b');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['contact_a', 'contact_b']);
            });
        }

        $svc = app(ContactMergeService::class);
        foreach (DB::table('contacts')->whereNotNull('wa_id')->where('wa_id', '<>', '')->orderBy('id')->get(['id', 'wa_id']) as $c) {
            $norm = Telefono::normalizar($c->wa_id);
            if ($norm === $c->wa_id) continue;
            $otro = DB::table('contacts')->where('wa_id', $norm)->where('id', '<>', $c->id)->first(['id', 'email']);
            if (!$otro) {
                DB::table('contacts')->where('id', $c->id)->update(['wa_id' => $norm]);
                continue;
            }
            // Mismo número tras normalizar → misma persona. Manda la ficha con correo.
            $esta = DB::table('contacts')->where('id', $c->id)->first(['id', 'email']);
            [$keep, $merge] = (!empty($esta->email) || empty($otro->email)) ? [$c->id, $otro->id] : [$otro->id, $c->id];
            DB::table('contacts')->where('id', $c->id)->update(['wa_id' => null]);
            $svc->fusionar((int) $keep, (int) $merge);
            DB::table('contacts')->where('id', $keep)->whereNull('wa_id')->update(['wa_id' => $norm]);
        }
        // Agendas de Campañas, al mismo formato.
        foreach (DB::table('phonebook_contacts')->get(['id', 'wa_id']) as $p) {
            $norm = Telefono::normalizar($p->wa_id);
            if ($norm && $norm !== $p->wa_id) DB::table('phonebook_contacts')->where('id', $p->id)->update(['wa_id' => $norm]);
        }

        DB::statement("UPDATE contacts SET email = LOWER(TRIM(email)) WHERE email IS NOT NULL AND email <> LOWER(TRIM(email))");
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_duplicate_ignores');
    }
};
