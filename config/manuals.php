<?php

/*
|--------------------------------------------------------------------------
| Manuales descargables (apartado «Ayuda» de la app)
|--------------------------------------------------------------------------
| Catálogo de los PDF que el equipo puede descargar. Los ficheros viven en
| resources/manuals/ (no son públicos: se sirven autenticados). Para AÑADIR
| un manual: deja el PDF en esa carpeta y añade aquí una entrada.
|
| `perms`: quién lo ve. El usuario lo ve si tiene ALGUNO de esos permisos
| (canAny). El superadministrador ve todos (bypass de Gate). El valor '*'
| significa «cualquier usuario con sesión».
|
| Referencia rápida de permisos por rol (config/rbac.php):
|   · helpdesk.access  → agente de soporte y encargado de soporte
|   · campaigns.access → encargado de campañas
|   · support.config   → encargado de soporte (config de soporte)
|   · campaigns.delete → encargado de campañas
|   · settings.manage  → solo superadministrador
*/

return [

    'catalog' => [

        'roles' => [
            'title' => 'Roles y permisos',
            'desc'  => 'Quién puede ver y hacer qué en el Helpdesk.',
            'file'  => '01-roles-y-permisos.pdf',
            'perms' => ['support.config', 'settings.manage', 'campaigns.delete'], // encargados y superadmin
        ],

        'usuario_soporte' => [
            'title' => 'Manual de usuario · Agente de soporte',
            'desc'  => 'El día a día atendiendo tickets: bandeja, respuestas, SLA y más.',
            'file'  => '02a-usuario-soporte.pdf',
            'perms' => ['helpdesk.access'],
        ],

        'usuario_campanas' => [
            'title' => 'Manual de usuario · Campañas',
            'desc'  => 'Difusiones por WhatsApp y correo, plantillas, formularios y chat en vivo.',
            'file'  => '02b-usuario-campanas.pdf',
            'perms' => ['campaigns.access'],
        ],

        'encargado_soporte' => [
            'title' => 'Manual del encargado de soporte',
            'desc'  => 'Repartir el trabajo, medir con informes y configurar el soporte.',
            'file'  => '03-encargado-soporte.pdf',
            'perms' => ['support.config'], // encargado de soporte y superadmin
        ],

        'cliente' => [
            'title' => 'Guía del portal de soporte (cliente)',
            'desc'  => 'La guía que ve el cliente en el portal: entrar, abrir y seguir incidencias. Para dársela a los clientes.',
            'file'  => '04-cliente.pdf',
            'perms' => ['support.config'], // interno: encargado y superadmin la descargan para el cliente
        ],

        'aplicacion' => [
            'title' => 'Guía general de la plataforma',
            'desc'  => 'El mapa de todo el sistema: áreas, vocabulario y cómo encaja cada pieza.',
            'file'  => '05-aplicacion.pdf',
            'perms' => ['*'], // visión general: cualquier usuario con sesión
        ],

        'potencialidad' => [
            'title' => 'Potencialidad y hoja de ruta',
            'desc'  => 'Documento estratégico: el estado real de hoy y las líneas de futuro a valorar.',
            'file'  => '06-potencialidad.pdf',
            'perms' => ['settings.manage'], // solo superadministrador
        ],
    ],

];
