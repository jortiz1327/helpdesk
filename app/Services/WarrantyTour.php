<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Tour guiado de Gestión de garantías: los textos de cada paso (editables en
 * Configuración) y quién lo ha visto ya, para ofrecerlo una sola vez. Qué se señala en
 * cada paso lo decide la interfaz (por id); aquí solo van el orden y los textos.
 */
class WarrantyTour
{
    public const DEFAULTS = [
        ['id' => 'inicio', 'title' => 'Gestión de garantías', 'text' => 'Te enseñamos cada apartado en un par de minutos. Avanza con «Siguiente» o con las flechas del teclado, y cierra cuando quieras con Esc.'],
        ['id' => 'tickets', 'title' => 'Tickets de garantías', 'text' => 'Los tickets de soporte de la categoría Garantías. Desde cada uno se abre la garantía con los datos del cliente, y quedan enlazados.'],
        ['id' => 'garantias', 'title' => 'Garantías', 'text' => 'Todos los expedientes de garantía: aquí se crean, se buscan y se abren.'],
        ['id' => 'filtros', 'title' => 'Buscar y filtrar', 'text' => 'Busca por expediente, cliente, proyecto o ticket. Las pestañas separan las abiertas, las tuyas, las pendientes de ZKONG y las cerradas; en «Más filtros» están las que tienen alertas, las críticas, las que no tienen responsable…'],
        ['id' => 'acciones', 'title' => 'Nueva garantía y exportar', 'text' => '«Nueva garantía» pide los datos y las etiquetas, que se comprueban en ZKONG al momento. «Exportar» descarga en CSV lo que estás viendo.'],
        ['id' => 'ficha', 'title' => 'La ficha de una garantía', 'text' => 'Arriba, el estado, la urgencia, las alertas y el responsable. Desde aquí se cambia el estado, se cierra o se editan los datos. (Es una garantía de ejemplo: el tour no cambia nada).'],
        ['id' => 'etiquetas', 'title' => 'Etiquetas y documentos', 'text' => 'En Etiquetas se revisa cada dispositivo: avería, fotos y quién paga. En Documentos están el informe, el texto para Finanzas, el correo de resolución, la pegatina del paquete y las sustituciones.'],
        ['id' => 'pasos', 'title' => 'Pasos', 'text' => 'Lo que hay que hacer con esta garantía según el tipo de cliente. Se marcan al hacerlos y queda quién y cuándo.'],
        ['id' => 'historial', 'title' => 'Seguimiento e historial', 'text' => 'Las notas del equipo, cada cambio y lo que escribe el cliente en el ticket, en una sola línea de tiempo.'],
        ['id' => 'equipo', 'title' => 'Equipo', 'text' => 'La carga de cada responsable: abiertas, críticas y sin actividad. Pulsa un número para ver esas garantías en la lista.'],
        ['id' => 'informes', 'title' => 'Informes', 'text' => 'Las garantías recibidas en un periodo, con su desglose y exportación a CSV.'],
        ['id' => 'clientes', 'title' => 'Clientes y proyectos', 'text' => 'Las garantías de cada cliente y sus datos del portal AEME, solo para consultar.'],
        ['id' => 'zkong', 'title' => 'ZKONG', 'text' => 'La bandeja de lo que hay que mandar a ZKONG y las remesas de RMA: se crean, se envían, se reciben y se cierran.'],
        ['id' => 'guias', 'title' => 'Protocolos y guías', 'text' => 'La documentación de cómo se trabaja: procedimientos, condiciones de garantía, guías técnicas y plantillas. En la ficha de cada garantía salen los documentos de su cliente o categoría.'],
        ['id' => 'dispositivos', 'title' => 'Centro de dispositivos', 'text' => 'Busca cualquier etiqueta o antena en los servidores ZKONG: dónde está, cómo está y en qué garantías ha estado.'],
        ['id' => 'catalogo', 'title' => 'Catálogo', 'text' => 'Los modelos de etiqueta, sus pilas y las referencias ZKONG ↔ AEME.'],
        ['id' => 'config', 'title' => 'Configuración', 'text' => 'Estados, categorías, alertas, pasos, plazos, los textos de los documentos y los de este tour.'],
        ['id' => 'fin', 'title' => 'Eso es todo', 'text' => 'Puedes repetir el tour cuando quieras desde «Tour guiado», en el menú.'],
    ];

    /** Los pasos con lo cambiado en Configuración: [{id, title, text, active}]. */
    public static function settings(): array
    {
        $v = (array) (\App\Models\WarrantyDocument::setting('tour')['steps'] ?? []);
        return array_map(function ($d) use ($v) {
            $c = (array) ($v[$d['id']] ?? []);
            return ['id' => $d['id'],
                'title' => ($c['title'] ?? '') !== '' ? $c['title'] : $d['title'],
                'text' => ($c['text'] ?? '') !== '' ? $c['text'] : $d['text'],
                'active' => array_key_exists('active', $c) ? (bool) $c['active'] : true];
        }, self::DEFAULTS);
    }

    /** Lo de Configuración, validado: solo lo que difiere de serie. [steps, error]. */
    public static function clean(array $in): array
    {
        $porId = collect($in)->keyBy(fn ($s) => (string) ($s['id'] ?? ''));
        $out = [];
        foreach (self::DEFAULTS as $d) {
            $s = (array) ($porId[$d['id']] ?? []);
            $t = mb_substr(trim((string) ($s['title'] ?? '')), 0, 80);
            $x = mb_substr(trim((string) ($s['text'] ?? '')), 0, 600);
            if (array_key_exists('title', $s) && $t === '') return [null, 'Cada paso necesita un título'];
            if (array_key_exists('text', $s) && $x === '') return [null, "«{$t}» necesita un texto"];
            $c = [];
            if ($t !== '' && $t !== $d['title']) $c['title'] = $t;
            if ($x !== '' && $x !== $d['text']) $c['text'] = $x;
            if (array_key_exists('active', $s) && ! $s['active']) $c['active'] = false;
            if ($c) $out[$d['id']] = $c;
        }
        return [$out, null];
    }

    public static function seen(int $userId): bool
    {
        return DB::table('warranty_tour_views')->where('user_id', $userId)->exists();
    }

    public static function markSeen(int $userId): void
    {
        DB::table('warranty_tour_views')->updateOrInsert(['user_id' => $userId], ['seen_at' => now()]);
    }
}
