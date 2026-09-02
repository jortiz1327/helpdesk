<?php

/*
|--------------------------------------------------------------------------
| RBAC — Módulos, permisos y roles
|--------------------------------------------------------------------------
| FUENTE ÚNICA DE VERDAD del control de acceso. Para añadir un permiso o un
| rol nuevo se toca SOLO este fichero y se relanza:
|
|     php artisan db:seed --class=RolesPermissionsSeeder
|
| El seeder es idempotente: crea lo que falta y sincroniza los roles.
| Los permisos `*.access` son los que deciden qué MÓDULOS ve cada usuario
| en el menú lateral (el frontend los recibe al iniciar sesión).
*/

return [

    // Módulos de la plataforma. El permiso `access` de cada uno controla la
    // visibilidad del módulo en el menú.
    'modules' => [
        'helpdesk'    => ['label' => 'Helpdesk',          'access' => 'helpdesk.access'],
        'contacts'    => ['label' => 'Contactos',         'access' => 'contacts.access'],
        'campaigns'   => ['label' => 'Campañas',          'access' => 'campaigns.access'],
        'automations' => ['label' => 'Automatizaciones',  'access' => 'automations.access'],
        'shifts'      => ['label' => 'Turnos',            'access' => 'shifts.access'],
        'admin'       => ['label' => 'Administración',    'access' => 'admin.access'],
    ],

    // Permisos: nombre => [etiqueta legible, módulo al que pertenece]
    'permissions' => [
        // --- Helpdesk / tickets ---
        'helpdesk.access'    => ['Acceder al Helpdesk',                        'helpdesk'],
        'tickets.create'     => ['Crear tickets',                              'helpdesk'],
        'tickets.view_all'   => ['Ver TODOS los tickets (no solo los propios)', 'helpdesk'],
        'tickets.reply'      => ['Responder tickets',                          'helpdesk'],
        'tickets.assign'     => ['Asignar tickets a agentes',                  'helpdesk'],
        'tickets.categorize' => ['Cambiar la categoría de tickets',            'helpdesk'],
        'tickets.close'      => ['Resolver y cerrar tickets',                  'helpdesk'],
        'tickets.delete'     => ['Eliminar tickets',                           'helpdesk'],
        // Métricas de rendimiento: un agente NO ve sus tiempos ni los de sus compañeros.
        'tickets.view_times' => ['Ver tiempos de atención y resolución',       'helpdesk'],
        'agents.view'        => ['Ver la carga de trabajo de los agentes',     'helpdesk'],
        'tickets.export'     => ['Exportar tickets',                           'helpdesk'],
        'support.config'     => ['Configurar el soporte (categorías, respuestas)', 'helpdesk'],

        // --- Contactos ---
        'contacts.access'    => ['Ver contactos',                              'contacts'],
        'contacts.edit'      => ['Editar contactos y etiquetas',               'contacts'],

        // --- Campañas / difusiones ---
        'campaigns.access'   => ['Acceder a Campañas',                         'campaigns'],
        'campaigns.send'     => ['Enviar campañas',                            'campaigns'],
        'campaigns.delete'   => ['Eliminar campañas',                          'campaigns'],
        'templates.manage'   => ['Gestionar plantillas de WhatsApp',           'campaigns'],
        'forms.manage'       => ['Gestionar formularios',                      'campaigns'],

        // --- Automatizaciones / bots ---
        'automations.access' => ['Acceder a automatizaciones',                 'automations'],
        'automations.manage' => ['Crear y editar flujos del bot',              'automations'],

        // --- Turnos ---
        'shifts.access'      => ['Ver el cuadrante de turnos',                 'shifts'],
        'shifts.manage'      => ['Gestionar turnos',                           'shifts'],

        // --- Administración ---
        'admin.access'       => ['Acceder a Administración',                   'admin'],
        'analytics.view'     => ['Ver analíticas',                             'admin'],
        // Registro de acciones (auditoría). NO se asigna a ningún rol de abajo:
        // así solo el superadministrador lo ve (bypass de Gate::before).
        'activity.view'      => ['Ver el registro de acciones',                'admin'],
        'users.manage'       => ['Gestionar usuarios',                         'admin'],
        'roles.manage'       => ['Gestionar roles y permisos',                 'admin'],
        'settings.manage'    => ['Configuración de la plataforma',             'admin'],
    ],

    /*
    | Roles. El rol `superadmin` es especial: NO se le listan permisos porque
    | tiene un bypass (Gate::before) que le concede todo automáticamente. Así,
    | cualquier permiso que añadamos en el futuro lo tiene sin tocar nada.
    */
    'roles' => [
        'superadmin' => [
            'label'       => 'Superadministrador',
            'description' => 'Acceso total a la plataforma. Todos los permisos, presentes y futuros.',
            'permissions' => '*',
        ],

        'encargado_soporte' => [
            'label'       => 'Encargado de soporte',
            'description' => 'Ve todos los tickets, reparte el trabajo, mide tiempos y gestiona los turnos.',
            'permissions' => [
                'helpdesk.access', 'tickets.create', 'tickets.view_all', 'tickets.reply', 'tickets.assign',
                'tickets.categorize', 'tickets.close', 'tickets.delete',
                'tickets.view_times', 'agents.view', 'tickets.export', 'support.config',
                'contacts.access', 'contacts.edit',
                'shifts.access', 'shifts.manage',
                'admin.access', 'analytics.view',
            ],
        ],

        /*
         * El agente ve SOLO los tickets de sus categorías (sus áreas), no todos.
         * Se le asignan categorías desde Usuarios. NO tiene `tickets.view_all`: esa es
         * justo la diferencia con el encargado. Tampoco ve métricas del equipo.
         */
        'agente' => [
            'label'       => 'Agente de soporte',
            'description' => 'Atiende los tickets de sus categorías asignadas. No ve los de otras áreas.',
            'permissions' => [
                'helpdesk.access', 'tickets.create', 'tickets.reply', 'tickets.categorize', 'tickets.close',
                'contacts.access',
                'shifts.access',
            ],
        ],

        'encargado_campanas' => [
            'label'       => 'Encargado de campañas',
            'description' => 'Gestiona plantillas, agendas, envío de campañas, chat en vivo y sus analíticas. No accede al Helpdesk, a la configuración de WhatsApp ni a los flujos de automatización (eso es del superadmin).',
            'permissions' => [
                'campaigns.access', 'campaigns.send', 'campaigns.delete',
                'templates.manage', 'forms.manage',
                'contacts.access',
                // La configuración de WhatsApp (settings.manage) y los FLUJOS DE AUTOMATIZACIÓN
                // (automations.*) se dejan SOLO al superadmin.
                'analytics.view',
            ],
        ],
    ],

    // Rol que se asigna por defecto a un usuario nuevo si no se indica otro.
    'default_role' => 'agente',

    // Rol con bypass total (Gate::before).
    'super_role' => 'superadmin',
];
