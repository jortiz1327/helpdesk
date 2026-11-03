<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Centro de ayuda de Gestión de garantías: artículos por apartado, con relacionados.
 * Los de serie están aquí; si se cambian, el cambio va a warranty_help_articles con
 * su slug (y «Texto original» lo quita). Los que se añaden van solo a la tabla.
 * Cada artículo se identifica por una clave: el slug si es de serie, «a{id}» si no.
 *
 * El texto admite: párrafos separados por una línea en blanco, «## » subtítulo,
 * «- » lista, «1. » lista numerada y **negrita**.
 *
 * step: el paso del tour que enlaza con este artículo («Más información»).
 */
class WarrantyHelp
{
    public const SECTIONS = [
        'primeros' => 'Primeros pasos',
        'garantias' => 'Garantías y ficha',
        'revision' => 'Etiquetas y revisión',
        'documentos' => 'Documentos y cierre',
        'zkong' => 'ZKONG y dispositivos',
        'equipo' => 'Equipo, informes y clientes',
        'config' => 'Configuración y permisos',
    ];

    public const DEFAULTS = [
        ['slug' => 'que-es', 'section' => 'primeros', 'step' => 'inicio', 'related' => ['recorrido', 'buscar', 'permisos'],
            'title' => 'Qué es Gestión de garantías',
            'summary' => 'Para qué sirve el área y qué hay en cada apartado del menú.',
            'body' => "Gestión de garantías reúne todo lo que pasa con un dispositivo que vuelve de un cliente: desde el ticket en el que avisa hasta que la garantía se cierra con su motivo.\n\n## Los apartados del menú\n- **Tickets de garantías**: los tickets de soporte de la categoría Garantías.\n- **Garantías**: la lista de expedientes y la ficha de cada uno.\n- **Equipo**: la carga de trabajo de cada responsable.\n- **Informes**: lo recibido en un periodo, con desglose y CSV.\n- **Clientes y proyectos**: las garantías de cada cliente y sus datos del portal AEME.\n- **ZKONG**: la bandeja de lo que hay que mandar al fabricante y las remesas de RMA.\n- **Protocolos y guías**: la documentación de cómo se trabaja.\n- **Centro de dispositivos** y **Catálogo**: buscar etiquetas y antenas, y los modelos.\n- **Configuración**: estados, categorías, alertas, pasos, textos… (solo quien la gestiona).\n\nCada uno ve solo lo que su rol le deja usar. Si te falta algo, pídeselo a quien gestiona los permisos.\n\nEl **Tour guiado** del menú enseña todo esto en un par de minutos."],
        ['slug' => 'recorrido', 'section' => 'primeros', 'step' => null, 'related' => ['crear', 'revisar', 'cerrar'],
            'title' => 'El recorrido de una garantía',
            'summary' => 'Los pasos de principio a fin, en orden.',
            'body' => "Así avanza una garantía normal. No todas pasan por todo: depende del cliente y de lo que se encuentre en la revisión.\n\n1. **Llega el aviso.** El cliente escribe y el ticket cae en Tickets de garantías.\n2. **Se crea la garantía** desde el ticket (o desde «Nueva garantía») con el cliente, el proyecto y las etiquetas, que se comprueban en ZKONG al momento.\n3. **Se asigna un responsable** y se marca la urgencia.\n4. **Se revisan las etiquetas**: avería, fotos y quién paga cada una.\n5. **Lo que va al fabricante** pasa a la bandeja de ZKONG y sale en una remesa de RMA.\n6. **Se preparan los documentos**: informe, texto para Finanzas, correo de resolución y, si hace falta, sustituciones.\n7. **Se siguen los pasos** de su tipo de cliente y se marcan al hacerlos.\n8. **Se cierra** con el motivo y cómo se ha resuelto.\n\nTodo queda en el historial de la ficha: quién hizo qué y cuándo."],
        ['slug' => 'tickets', 'section' => 'garantias', 'step' => 'tickets', 'related' => ['crear', 'historial'],
            'title' => 'Tickets de garantías',
            'summary' => 'De dónde salen las garantías y cómo quedan enlazadas al ticket.',
            'body' => "En **Tickets de garantías** están los tickets de soporte de la categoría Garantías: es el mismo Helpdesk, filtrado.\n\nDesde un ticket se crea la garantía con los datos del cliente ya puestos. A partir de ahí quedan enlazados:\n- En la ficha se ve el ticket y se abre con un clic.\n- Lo que escribe el cliente en el ticket aparece en el historial de la garantía.\n- Las fotos que manda el cliente se pueden pasar a las etiquetas («Fotos del cliente»).\n\nPara ver este apartado hace falta también acceso al Helpdesk."],
        ['slug' => 'crear', 'section' => 'garantias', 'step' => 'acciones', 'related' => ['tickets', 'buscar', 'revisar'],
            'title' => 'Crear una garantía',
            'summary' => 'Qué datos pide y cómo se comprueban las etiquetas.',
            'body' => "Pulsa **Nueva garantía** en la lista (o créala desde su ticket).\n\n## Qué se pide\n- **Cliente y proyecto** (el hotel o la tienda). El cliente se busca en el portal AEME.\n- **Categoría** (Hoteles, Farmacias…): decide qué pasos tocan y qué documentación sale en la ficha.\n- **Urgencia**, **responsable** y fecha de recepción.\n- **Etiquetas**: pega o escribe los códigos. Cada una se busca en los servidores ZKONG y se ve al momento si existe, qué modelo es y dónde está.\n\nLas etiquetas que no aparecen se pueden dejar igualmente y volver a comprobar más tarde desde la ficha («Volver a comprobar»).\n\n**Exportar**, al lado, descarga en CSV lo que estás viendo en la lista."],
        ['slug' => 'buscar', 'section' => 'garantias', 'step' => 'filtros', 'related' => ['ficha', 'estados', 'equipo'],
            'title' => 'Buscar y filtrar la lista',
            'summary' => 'Pestañas, filtros, exportar y cambios en lote.',
            'body' => "El buscador encuentra por número de garantía, cliente, proyecto o ticket.\n\n## Pestañas\n- **Abiertas**: las que siguen en curso.\n- **Mías**: las que tienes como responsable.\n- **Pte. ZKONG**: las que tienen algo pendiente con el fabricante.\n- **Cerradas** y **Todas**.\n\n## Más filtros\nResponsable, categoría y, en «Más filtros», las que tienen alertas, las críticas, las que no tienen responsable…\n\n## Cambios en lote\nMarca varias garantías y cambia a la vez el estado o el responsable. Si el cambio las cierra o las reabre, se pide el motivo una sola vez para todas."],
        ['slug' => 'ficha', 'section' => 'garantias', 'step' => 'ficha', 'related' => ['estados', 'pasos', 'historial'],
            'title' => 'La ficha de una garantía',
            'summary' => 'Qué hay en cada parte de la ficha.',
            'body' => "Al abrir una garantía se ve su ficha.\n\n## Arriba\nEl número, el cliente, el estado, la urgencia, las alertas y el responsable. Desde aquí se cambia el estado, se **cierra**, se ven las **Fotos del cliente** y se **editan** los datos.\n\n## En el centro\n- **Etiquetas**: cada dispositivo con su revisión.\n- **Documentos**: informe, texto para Finanzas, correo, pegatina y sustituciones.\n\n## A la derecha\n- **Pasos** de su tipo de cliente.\n- **Documentación** que corresponde a su cliente o categoría.\n- **Datos del expediente**.\n\n## Abajo\nEl **seguimiento**: notas del equipo, cambios y mensajes del cliente."],
        ['slug' => 'estados', 'section' => 'garantias', 'step' => null, 'related' => ['ficha', 'cerrar', 'configuracion'],
            'title' => 'Estados, urgencias y alertas',
            'summary' => 'Cómo se lee el avance de una garantía y cuándo avisa la app.',
            'body' => "## Estado\nDice en qué punto está la garantía. Algunos estados cuentan como **cerrada** (Cerrada, Descartada, Resuelta): al pasar a ellos se pide el motivo de cierre.\n\n## Urgencia\nMuy urgente, Alta, Media o Baja. Sirve para ordenar el trabajo y sale en la lista y en Equipo.\n\n## Alertas\nLa app avisa cuando una garantía lleva demasiados días sin moverse en un punto: primero un aviso y, si sigue, **crítica**. Los días de cada regla se ajustan en Configuración → Alertas.\n\nLas alertas salen en la ficha, en la lista («Más filtros») y en Equipo."],
        ['slug' => 'pasos', 'section' => 'garantias', 'step' => 'pasos', 'related' => ['ficha', 'guias', 'configuracion'],
            'title' => 'Pasos por tipo de cliente',
            'summary' => 'La lista de lo que hay que hacer según el cliente.',
            'body' => "Cada garantía tiene una lista de pasos según su tipo de cliente: unos para todas, otros solo para una categoría (Hoteles…) o para un cliente concreto (Expofarm…).\n\nSe marcan en la ficha al hacerlos. Queda **quién** y **cuándo**, y también sale en el historial.\n\nLas listas y sus pasos se cambian en Configuración → Pasos por tipo de cliente. Para marcar hace falta poder editar garantías."],
        ['slug' => 'historial', 'section' => 'garantias', 'step' => 'historial', 'related' => ['tickets', 'ficha'],
            'title' => 'Seguimiento e historial',
            'summary' => 'Notas del equipo, cambios y mensajes del cliente en una línea de tiempo.',
            'body' => "Abajo de la ficha está todo lo que ha pasado con la garantía, por orden:\n- **Notas** que escribe el equipo.\n- **Cambios**: estado, responsable, urgencia, pasos marcados, cierres y reaperturas con su motivo.\n- **Mensajes del cliente** en el ticket enlazado.\n\nUna nota sirve para dejar constancia de algo que no es un cambio: una llamada, lo que dijo el cliente, lo que falta."],
        ['slug' => 'revisar', 'section' => 'revision', 'step' => 'etiquetas', 'related' => ['zkong', 'sustituciones', 'documentos'],
            'title' => 'Revisar las etiquetas',
            'summary' => 'Avería, fotos, comentario técnico y quién paga.',
            'body' => "En la pestaña **Etiquetas** de la ficha está cada dispositivo de la garantía.\n\n## Al revisar uno\n- **Qué le pasa**: batería baja, no reproduce el fallo, defectuosa…\n- **Fotos**: se suben o se pasan desde las que mandó el cliente.\n- **Comentario técnico**: las plantillas de diagnóstico lo rellenan con un clic.\n- **Solución y quién paga**: decide qué sale en el informe y en el texto para Finanzas.\n\nLos filtros de arriba dejan ver solo las que faltan por revisar, las que no tienen fotos…\n\n**Volver a comprobar** consulta otra vez ZKONG para las etiquetas que no se encontraron al crear la garantía.\n\nPara revisar hace falta el permiso de revisar dispositivos."],
        ['slug' => 'sustituciones', 'section' => 'revision', 'step' => null, 'related' => ['revisar', 'documentos'],
            'title' => 'Sustituciones y ticket de posiciones',
            'summary' => 'Qué apuntar cuando una etiqueta se cambia por otra.',
            'body' => "Cuando una etiqueta no se devuelve y se cambia por una nueva, en **Documentos → Sustituciones** se apunta de cada una:\n- La **posición**: el artículo que tenía vinculado en ZKONG (se busca solo o se escribe).\n- La **etiqueta nueva**.\n\nEn algunas categorías (Hoteles, por ejemplo) se pregunta si crear un **ticket de posiciones** a nombre del cliente con la tabla Posición · Etiqueta antigua · Etiqueta nueva, para vincularlas. El ticket no le escribe nada al cliente.\n\nQué categorías y cómo sale el ticket se ajusta en Configuración → Sustituciones."],
        ['slug' => 'documentos', 'section' => 'documentos', 'step' => null, 'related' => ['revisar', 'cerrar', 'sustituciones'],
            'title' => 'Informe, Finanzas, correo y pegatina',
            'summary' => 'Los documentos que salen de una garantía.',
            'body' => "En la pestaña **Documentos** de la ficha, con lo que se ha revisado:\n- **Informe de resolución**: el PDF para el cliente con cada dispositivo y su solución.\n- **Texto para Finanzas**: lo que hay que facturar o abonar, agrupado por solución.\n- **Presupuestos para el portal**: uno por quién paga, con los precios del portal AEME.\n- **Correo de resolución**: el texto para el cliente, que se revisa antes de mandarlo.\n- **Pegatina del paquete**: en PDF, para imprimir.\n\nLos textos de serie, la empresa del informe y los plazos de garantía se cambian en Configuración. Para preparar documentos hace falta su permiso."],
        ['slug' => 'cerrar', 'section' => 'documentos', 'step' => null, 'related' => ['estados', 'documentos', 'informes'],
            'title' => 'Cerrar y reabrir una garantía',
            'summary' => 'El motivo de cierre, cómo se resolvió y por qué se reabre.',
            'body' => "## Cerrar\nCon **Cerrar** en la ficha, eligiendo un estado cerrado o desde el cambio en lote, se pide:\n- El **motivo de cierre** (aceptada, rechazada, sustitución, reparación…).\n- **Cómo se resolvió**, en una línea.\n\nQueda en la ficha, en el historial, en el CSV y en Informes se puede agrupar por motivo.\n\n## Reabrir\n**Reabrir** pide por qué y deja cambiar el responsable. El motivo de cierre anterior se quita y la reapertura queda en el historial.\n\nLa lista de motivos se edita en Configuración → Motivos de cierre. Un motivo que ya se ha usado no se borra: se desactiva."],
        ['slug' => 'guias', 'section' => 'documentos', 'step' => 'guias', 'related' => ['pasos', 'ficha'],
            'title' => 'Protocolos y guías',
            'summary' => 'La documentación de cómo se trabaja y cuál sale en cada garantía.',
            'body' => "En **Protocolos y guías** están los documentos de trabajo: procedimientos internos, condiciones de garantía, guías técnicas, plantillas, procedimientos de cliente y operativa.\n\nCada documento dice para qué garantías vale: **todas** (general) o solo algunas categorías o clientes. En la ficha de cada garantía, en **Documentación**, salen primero los de su cliente o categoría y después los generales.\n\nLos PDF y las imágenes se abren en un visor; el resto se descarga.\n\nSubir, cambiar y ocultar documentos es de quien gestiona la configuración. Un documento oculto solo lo ve esa persona."],
        ['slug' => 'zkong', 'section' => 'zkong', 'step' => 'zkong', 'related' => ['revisar', 'dispositivos'],
            'title' => 'Bandeja de ZKONG y remesas de RMA',
            'summary' => 'Cómo se manda al fabricante lo que no se resuelve aquí.',
            'body' => "Lo que hay que mandar a ZKONG cae en la **bandeja**, con su garantía, cliente, fallo y fotos. Avisa de lo que lleva más días esperando y de lo que no tiene fotos.\n\n## Remesas de RMA\n1. **Se crea** una remesa y se le añade lo de la bandeja.\n2. **Se envía**, con la fecha y los datos del envío.\n3. **Se recibe** la respuesta y se apunta el resultado de cada etiqueta.\n4. **Se cierra** cuando está todo.\n\nUna remesa se puede anular dando el motivo. Gestionar remesas pide su permiso."],
        ['slug' => 'dispositivos', 'section' => 'zkong', 'step' => 'dispositivos', 'related' => ['catalogo', 'zkong'],
            'title' => 'Centro de dispositivos',
            'summary' => 'Buscar cualquier etiqueta o antena en los servidores ZKONG.',
            'body' => "Escribe o pega los códigos de etiquetas o antenas y se buscan en todos los servidores ZKONG: en qué servidor y tienda están, su modelo y firmware, la señal y qué artículo tienen vinculado.\n\nDebajo salen las **garantías** en las que ha estado ese dispositivo, para abrirlas directamente.\n\nSirve antes de crear una garantía, para ver si una etiqueta ya volvió otra vez."],
        ['slug' => 'catalogo', 'section' => 'zkong', 'step' => 'catalogo', 'related' => ['dispositivos', 'documentos'],
            'title' => 'Catálogo de modelos',
            'summary' => 'Los modelos de etiqueta, sus pilas y las referencias.',
            'body' => "El **Catálogo** recoge los modelos de etiqueta con sus pilas y la equivalencia entre la referencia ZKONG y la de AEME.\n\nDe aquí sale el modelo que se ve en cada etiqueta de una garantía."],
        ['slug' => 'equipo', 'section' => 'equipo', 'step' => 'equipo', 'related' => ['buscar', 'informes'],
            'title' => 'Equipo',
            'summary' => 'La carga de trabajo de cada responsable.',
            'body' => "En **Equipo** se ve, por responsable, cuántas garantías tiene abiertas, cuántas con alertas o críticas, cuántas esperan al cliente o a ZKONG y los dispositivos que le quedan por revisar.\n\nPulsa cualquier número para abrir la lista de Garantías con esas garantías ya filtradas."],
        ['slug' => 'informes', 'section' => 'equipo', 'step' => 'informes', 'related' => ['cerrar', 'equipo'],
            'title' => 'Informes',
            'summary' => 'Lo recibido en un periodo, con su desglose.',
            'body' => "Elige un periodo y se ven las garantías recibidas en él, agrupadas por estado, urgencia, categoría, responsable, cliente, mes o motivo de cierre, con el tiempo medio hasta el cierre, cómo se han resuelto los dispositivos y las respuestas de ZKONG.\n\nTodo se puede exportar a CSV."],
        ['slug' => 'clientes', 'section' => 'equipo', 'step' => 'clientes', 'related' => ['crear', 'informes'],
            'title' => 'Clientes y proyectos',
            'summary' => 'Las garantías de cada cliente y sus datos del portal.',
            'body' => "Busca un cliente y se ven sus proyectos (hoteles, tiendas) y todas sus garantías, abiertas y cerradas.\n\nLos datos del cliente vienen del portal AEME y aquí solo se consultan: si algo está mal, se corrige en el portal."],
        ['slug' => 'configuracion', 'section' => 'config', 'step' => 'config', 'related' => ['permisos', 'estados', 'pasos'],
            'title' => 'Qué se ajusta en Configuración',
            'summary' => 'Lo que se puede cambiar sin tocar código.',
            'body' => "En **Configuración** (solo quien la gestiona):\n- **Estados**, **categorías** y **urgencias**.\n- **Alertas**: los días de aviso y de crítico.\n- **Pasos por tipo de cliente**.\n- **Motivos de cierre**.\n- **Plantillas de diagnóstico** para la revisión.\n- **Plazos de garantía**, **documentos y correo**, **texto para Finanzas** y **sustituciones**.\n- Los textos del **tour guiado**.\n\nLos precios y tarifas no están aquí: son los del portal AEME.\n\nLos artículos de este Centro de ayuda se editan aquí mismo, con «Editar» en cada uno."],
        ['slug' => 'permisos', 'section' => 'config', 'step' => null, 'related' => ['configuracion', 'que-es'],
            'title' => 'Qué puede hacer cada uno',
            'summary' => 'Los permisos por parte y los roles de garantías.',
            'body' => "Los permisos de garantías van por partes: ver, editar, revisar dispositivos, preparar documentos, crear el ticket de posiciones, gestionar las remesas de ZKONG, configurar y borrar.\n\n## Roles\n- **Responsable de garantías**: todo lo del día a día y la configuración.\n- **Técnico de garantías**: revisar dispositivos y ZKONG.\n- **Consulta de garantías**: ver, sin cambiar nada.\n\nCada uno ve en pantalla solo lo que puede hacer. Borrar garantías es del superadmin salvo que se dé a mano.\n\nLos roles y permisos se asignan en la administración de usuarios."],
    ];

    /** Todos (los inactivos solo si $inactivos), en orden de apartado. */
    public static function all(bool $inactivos = false): array
    {
        $filas = DB::table('warranty_help_articles')->orderBy('sort')->orderBy('id')->get();
        $cambios = $filas->whereNotNull('slug')->keyBy('slug');
        $out = [];
        foreach (self::DEFAULTS as $i => $d) {
            $f = $cambios[$d['slug']] ?? null;
            $out[] = [
                'key' => $d['slug'], 'custom' => false, 'edited' => (bool) $f, 'step' => $d['step'],
                'section' => $f->section ?? $d['section'], 'title' => $f->title ?? $d['title'],
                'summary' => $f ? (string) $f->summary : $d['summary'], 'body' => $f->body ?? $d['body'],
                'related' => $f ? (array) json_decode($f->related ?: '[]', true) : $d['related'],
                'active' => $f ? (bool) $f->active : true, 'order' => $i,
                'updated_at' => $f?->updated_at,
            ];
        }
        foreach ($filas->whereNull('slug') as $f) {
            $out[] = [
                'key' => 'a'.$f->id, 'custom' => true, 'edited' => true, 'step' => null,
                'section' => $f->section, 'title' => $f->title, 'summary' => (string) $f->summary, 'body' => $f->body,
                'related' => (array) json_decode($f->related ?: '[]', true), 'active' => (bool) $f->active,
                'order' => 1000 + (int) $f->sort, 'updated_at' => $f->updated_at,
            ];
        }
        $claves = array_column($out, 'key');
        $orden = array_flip(array_keys(self::SECTIONS));
        usort($out, fn ($a, $b) => [$orden[$a['section']] ?? 99, $a['order']] <=> [$orden[$b['section']] ?? 99, $b['order']]);
        $out = array_map(function ($a) use ($claves) {
            $a['related'] = array_values(array_intersect($a['related'], $claves));
            unset($a['order']);
            return $a;
        }, $out);
        return array_values(array_filter($out, fn ($a) => $inactivos || $a['active']));
    }

    /** Guarda uno (de serie: su cambio; nuevo o añadido: la fila). [clave, error]. */
    public static function save(array $in, int $uid): array
    {
        $key = (string) ($in['key'] ?? '');
        $title = mb_substr(trim((string) ($in['title'] ?? '')), 0, 120);
        $body = trim(str_replace("\r\n", "\n", (string) ($in['body'] ?? '')));
        $section = (string) ($in['section'] ?? '');
        if ($title === '') return [null, 'Falta el título'];
        if ($body === '') return [null, 'Falta el texto'];
        if (mb_strlen($body) > 20000) return [null, 'El texto es demasiado largo'];
        if (! isset(self::SECTIONS[$section])) return [null, 'Apartado no válido'];

        $claves = array_column(self::all(true), 'key');
        $rel = array_values(array_unique(array_filter(array_map('strval', (array) ($in['related'] ?? [])),
            fn ($k) => $k !== $key && in_array($k, $claves, true))));
        $datos = [
            'section' => $section, 'title' => $title,
            'summary' => mb_substr(trim((string) ($in['summary'] ?? '')), 0, 300) ?: null,
            'body' => $body, 'related' => json_encode(array_slice($rel, 0, 8)),
            'active' => array_key_exists('active', $in) ? (bool) $in['active'] : true,
            'updated_by' => $uid, 'updated_at' => now(),
        ];

        if (self::isDefault($key)) {
            DB::table('warranty_help_articles')->updateOrInsert(['slug' => $key], $datos + ['created_at' => now()]);
            return [$key, null];
        }
        if ($key !== '') {
            $id = self::customId($key);
            if (! $id || ! DB::table('warranty_help_articles')->where('id', $id)->whereNull('slug')->exists()) return [null, 'Artículo no encontrado'];
            DB::table('warranty_help_articles')->where('id', $id)->update($datos);
            return [$key, null];
        }
        $sort = (int) DB::table('warranty_help_articles')->whereNull('slug')->max('sort') + 1;
        $id = DB::table('warranty_help_articles')->insertGetId($datos + ['sort' => $sort, 'created_at' => now()]);
        return ['a'.$id, null];
    }

    /** De serie: vuelve al texto original. Añadido: se borra. */
    public static function remove(string $key): ?string
    {
        if (self::isDefault($key)) {
            DB::table('warranty_help_articles')->where('slug', $key)->delete();
            return null;
        }
        $id = self::customId($key);
        return $id && DB::table('warranty_help_articles')->where('id', $id)->whereNull('slug')->delete() ? null : 'Artículo no encontrado';
    }

    /** El artículo que explica cada paso del tour: [paso => clave] (solo activos). */
    public static function forTour(): array
    {
        $out = [];
        foreach (self::all() as $a) if ($a['step'] && ! isset($out[$a['step']])) $out[$a['step']] = $a['key'];
        return $out;
    }

    public static function isDefault(string $key): bool
    {
        return in_array($key, array_column(self::DEFAULTS, 'slug'), true);
    }

    private static function customId(string $key): ?int
    {
        return preg_match('/^a(\d+)$/', $key, $m) ? (int) $m[1] : null;
    }
}
