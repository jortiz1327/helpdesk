<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Preguntas frecuentes del portal, configurables desde Agentes → Configuración.
 * Antes vivían "quemadas" en el código del portal (Portal.jsx). Ahora:
 *  · el agente las crea/edita/ordena/publica desde su panel;
 *  · cada FAQ lleva PALABRAS CLAVE (cómo lo dice el cliente, aunque no salga en el
 *    título) para que el buscador del portal la encuentre;
 *  · se mide su utilidad: vistas + votos 👍/👎, para saber cuál desvía tickets y
 *    cuál hay que reescribir;
 *  · category_id la vincula a una categoría de ticket, para el CTA "no me sirve,
 *    abrir incidencia" que pre-rellena esa categoría.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 200);
            $table->text('answer');
            $table->string('hint', 255)->nullable();     // frase corta de una línea
            $table->string('keywords', 500)->nullable();  // términos de búsqueda separados por coma
            $table->unsignedBigInteger('category_id')->nullable();  // categoría de ticket vinculada
            $table->unsignedInteger('position')->default(0);
            $table->boolean('active')->default(true);      // publicada / borrador
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('helpful_yes')->default(0);
            $table->unsignedInteger('helpful_no')->default(0);
            $table->timestamps();

            $table->index('active');
            $table->index('position');
        });

        // Siembra: las 8 FAQ que hasta ahora estaban fijas en Portal.jsx, cada una
        // con palabras clave pensadas para cómo las escribiría el cliente.
        $now = now();
        $seed = [
            [
                'question' => 'Hoy no han cargado las etiquetas / no aparece el menú',
                'answer'   => 'Revisa que los repetidores estén en línea y prueba a forzar el menú desde el panel. Comprueba si la información de la etiqueta cambia. Si aun así no se muestra, abre una incidencia y lo miramos.',
                'hint'     => 'Suele ser un repetidor caído o el servicio sin forzar.',
                'keywords' => 'no cargan, pantalla en blanco, no se ve, no aparece, etiquetas vacías, sin menú, no se actualiza, en blanco',
            ],
            [
                'question' => '¿A qué hora cambia el servicio? ¿Cómo lo modifico?',
                'answer'   => 'El servicio cambia a la hora programada en la franja horaria del panel. Puedes modificarla en Servicios → horario, y forzar el cambio manualmente si lo necesitas antes.',
                'hint'     => '',
                'keywords' => 'cambio de servicio, horario, franja, a qué hora, cambiar servicio, programar servicio',
            ],
            [
                'question' => '¿Qué hago si no me deja clonar un servicio?',
                'answer'   => 'Comprueba que el servicio de origen esté guardado y activo. Si el botón sigue sin responder, cierra sesión, vuelve a entrar y prueba de nuevo antes de abrir incidencia.',
                'hint'     => '',
                'keywords' => 'clonar, duplicar servicio, copiar servicio, no me deja clonar, clonar servicio',
            ],
            [
                'question' => '¿Cómo elimino platos repetidos?',
                'answer'   => 'Entra en el menú, ordena por nombre para ver los duplicados juntos y elimínalos uno a uno. Al guardar, las etiquetas se actualizan en el siguiente cambio de servicio.',
                'hint'     => '',
                'keywords' => 'platos repetidos, duplicados, quitar plato, borrar plato, menú repetido, platos duplicados',
            ],
            [
                'question' => '¿Qué hacer si el repetidor AP está apagado?',
                'answer'   => 'Comprueba que esté enchufado y el piloto encendido. Si está en rojo, desconéctalo 10 segundos y vuelve a conectarlo. Si sigue apagado, es probable que sea avería: abre incidencia.',
                'hint'     => 'Casi siempre se soluciona reiniciando el AP.',
                'keywords' => 'repetidor apagado, ap apagado, repetidor rojo, sin conexión, repetidor no funciona, piloto rojo, antena apagada',
            ],
            [
                'question' => 'Cambio de menú de verano a invierno (o viceversa)',
                'answer'   => 'Desde Menús puedes tener varias versiones guardadas. Selecciona la temporada que toca y asígnala al servicio; en el siguiente cambio, las etiquetas mostrarán el nuevo menú.',
                'hint'     => '',
                'keywords' => 'cambiar menú, temporada, verano, invierno, menú de temporada, cambio de temporada',
            ],
            [
                'question' => 'Una etiqueta muestra "Template not matched"',
                'answer'   => 'La plantilla asignada no coincide con el tipo de etiqueta. Reasigna la plantilla correcta desde el detalle de la etiqueta y fuerza un refresco.',
                'hint'     => '',
                'keywords' => 'template not matched, plantilla, error etiqueta, no coincide, plantilla incorrecta',
            ],
            [
                'question' => 'Se ha roto una etiqueta electrónica',
                'answer'   => 'Anota el código de la etiqueta y ábrenos una incidencia indicando la tienda. Gestionamos la reposición y te confirmamos el envío del material.',
                'hint'     => '',
                'keywords' => 'etiqueta rota, rota, reponer, reposición, pantalla partida, no funciona etiqueta, etiqueta estropeada',
            ],
        ];

        $rows = [];
        foreach ($seed as $i => $f) {
            $rows[] = array_merge($f, [
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
        Schema::dropIfExists('faqs');
    }
};
