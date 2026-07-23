<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * La tabla `faqs` se generaliza a una pequeña BASE DE CONOCIMIENTO con dos
 * secciones fijas (como la «Knowledge base» de osTicket, traducida):
 *   · 'faq'  → Preguntas frecuentes (con palabras clave, votos y CTA).
 *   · 'info' → Centro de atención: fichas de info de la empresa (horario, correos,
 *              teléfonos). Solo título + contenido; sin votos ni palabras clave.
 * Se añade la columna `section` y se siembran las tres fichas del Centro de
 * atención con los datos de la web pública actual (a revisar por el usuario).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('faqs', function (Blueprint $table) {
            $table->string('section', 20)->default('faq')->after('id')->index();
        });

        $now = now();
        $info = [
            [
                'question' => 'Horario de servicio',
                'answer'   => 'Nuestro horario de atención es de lunes a viernes, de 7:00 a 21:00 h.',
            ],
            [
                'question' => 'Correos de contacto',
                'answer'   => "Información general: info@etiquetaselectronicas.com\n"
                    . "Comercial (solicitar material): comercial@aemegroup.com\n"
                    . 'Estado de tus garantías: garantias@etiquetaselectronicas.com',
            ],
            [
                'question' => 'Teléfonos',
                'answer'   => "Contacto: 962 012 074\n"
                    . "Información: 630 666 948\n"
                    . 'Soporte: 608 923 001',
            ],
        ];

        $rows = [];
        foreach ($info as $i => $f) {
            $rows[] = array_merge($f, [
                'section'     => 'info',
                'hint'        => null,
                'keywords'    => null,
                'category_id' => null,
                'position'    => $i + 1,
                'active'      => true,
                'views'       => 0,
                'helpful_yes' => 0,
                'helpful_no'  => 0,
                'created_at'  => $now,
                'updated_at'  => $now,
            ]);
        }
        DB::table('faqs')->insert($rows);
    }

    public function down(): void
    {
        DB::table('faqs')->where('section', 'info')->delete();
        Schema::table('faqs', function (Blueprint $table) {
            $table->dropIndex(['section']);
            $table->dropColumn('section');
        });
    }
};
