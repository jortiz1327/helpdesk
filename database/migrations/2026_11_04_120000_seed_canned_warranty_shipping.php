<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Respuesta predefinida /garantia-envio: tras revisar las fotos de una solicitud de
 * garantía, pedir al cliente que nos mande las etiquetas (dirección, cómo prepararlas,
 * cómo avisarnos y qué pasa después), según el protocolo de garantías. Si ya existe ese
 * atajo, no se toca (la habrán retocado).
 */
return new class extends Migration
{
    private const ATAJO = 'garantia-envio';

    public function up(): void
    {
        if (DB::table('canned_responses')->where('shortcut', self::ATAJO)->exists()) return;
        DB::table('canned_responses')->insert([
            'shortcut' => self::ATAJO,
            'title'    => 'Garantía: pedir el envío de las etiquetas',
            'body'     => <<<'TXT'
Hola {{cliente}},

Hemos revisado las fotos de tu solicitud {{codigo}}. Para comprobar las etiquetas necesitamos verlas en nuestras instalaciones, así que te pedimos que nos las envíes.

📍 Dirección de envío
AEME Group · A la atención del Departamento de Garantías
Calle Gutiérrez Mellado, 2
46250 L’Alcúdia, Valencia (España)
📞 962 012 074 / 608 923 001

📦 Antes de enviarlas
• Protégelas bien contra golpes: las revisamos tal como llegan.
• Escribe el número {{codigo}} en la caja.
• Los gastos de envío corren de tu parte.

✉️ Cuando las envíes
Responde a este mensaje con el enlace de seguimiento (si lo tienes) y cuántas etiquetas van en la caja.

🔎 Qué pasará después
Al recibirlas haremos una verificación técnica y te enviaremos un informe con el presupuesto: reparación si se pueden recuperar o sustitución por unidades nuevas si no. Para probarlas les cambiamos las pilas, así que te las devolvemos al recibir el justificante de pago del presupuesto aceptado.

⚠️ La garantía no cubre golpes, caídas, líquidos, calor extremo ni sobretensiones (lo tienes detallado en tu solicitud, en el portal).

Un saludo.
TXT,
            'position' => (int) DB::table('canned_responses')->max('position') + 1,
            'active'   => true,
        ]);
    }

    public function down(): void
    {
        DB::table('canned_responses')->where('shortcut', self::ATAJO)->delete();
    }
};
