<?php

use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BusinessHoursController;
use App\Http\Controllers\ShiftsController;
use App\Http\Controllers\CampaignsController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\ContactsController;
use App\Http\Controllers\CronAlertsController;
use App\Http\Controllers\CronStatusController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\EmailAccountsController;
use App\Http\Controllers\EmailBansController;
use App\Http\Controllers\EmailTemplatesController;
use App\Http\Controllers\FaqsController;
use App\Http\Controllers\FlowsController;
use App\Http\Controllers\FormsController;
use App\Http\Controllers\GatingController;
use App\Http\Controllers\InlineImageController;
use App\Http\Controllers\LabelsController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\OrganizationsController;
use App\Http\Controllers\PhonebooksController;
use App\Http\Controllers\ResponsesController;
use App\Http\Controllers\RolesController;
use App\Http\Controllers\SendController;
use App\Http\Controllers\SendMediaController;
use App\Http\Controllers\UploadMediaController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\SupportSettingsController;
use App\Http\Controllers\TemplatesController;
use App\Http\Controllers\TicketReportsController;
use App\Http\Controllers\TicketPrioritiesController;
use App\Http\Controllers\TicketRulesController;
use App\Http\Controllers\TicketSettingsController;
use App\Http\Controllers\TicketsController;
use App\Http\Controllers\UsersController;
use App\Http\Controllers\WebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
| Rutas de la API. Prefijo automático /api.
| Se conservan los nombres del original (auth.php, conversations.php, …)
| para que el frontend React funcione sin cambios.
*/

// --- Autenticación (pública; cada acción gestiona su propio login) ---
Route::match(['get', 'post'], 'auth.php', [AuthController::class, 'handle']);

// --- Webhook de WhatsApp (PÚBLICO; lo llama Meta, sin token) ---
// Rate-limit generoso: aguanta las ráfagas normales de Meta pero corta un flood.
// Un 429 hace que Meta reintente el evento (no se pierde), así que es seguro.
Route::match(['get', 'post'], 'webhook.php', [WebhookController::class, 'handle'])
    ->middleware('throttle:600,1');

/*
 * PORTAL PÚBLICO (la cara del cliente). SIN token de agente: la identidad es el
 * correo del cliente verificado con un código. Cada acción con datos comprueba el
 * «pase» por su cuenta (cabecera X-Portal-Token). Con throttle para el pedido de
 * código, que es lo que manda correos.
 */
Route::match(['get', 'post'], 'portal.php', [\App\Http\Controllers\PortalController::class, 'handle'])
    ->middleware('throttle:30,1');
// Adjuntos del portal: por URL FIRMADA (la firma es la auth, sin pase en la URL).
Route::get('portal_file/{id}', [\App\Http\Controllers\PortalController::class, 'file'])
    ->middleware('signed:relative')->name('portal.file')->whereNumber('id');

// Valoración (CSAT) por URL FIRMADA (desde las estrellas del correo, sin login).
Route::match(['get', 'post'], 'portal_rate/{ticket}', [\App\Http\Controllers\PortalController::class, 'ratePage'])
    ->middleware('signed:relative')->name('portal.rate')->whereNumber('ticket');

// --- Imágenes en línea del editor: servir por URL FIRMADA (sin token, la firma es la auth) ---
Route::get('inline/{id}', [InlineImageController::class, 'serve'])
    ->middleware('signed:relative')->name('inline.image')->whereNumber('id');

// --- Imágenes en línea de CORREO (las «cid:» de la firma): también por URL FIRMADA ---
Route::get('attachment_inline/{id}', [AttachmentController::class, 'serveInline'])
    ->middleware('signed:relative')->name('attachment.inline')->whereNumber('id');

// --- Baja de campañas por CORREO: enlace FIRMADO en cada envío (sin login; la firma es la auth) ---
// GET = el destinatario pulsa el enlace del pie. POST = «one-click» de Gmail/Outlook desde su
// botón nativo «Cancelar suscripción» (List-Unsubscribe-Post, RFC 8058).
Route::match(['get', 'post'], 'campaign_unsubscribe/{contact}', [CampaignsController::class, 'unsubscribe'])
    ->middleware('signed:relative')->name('campaign.unsubscribe')->whereNumber('contact');


/*
| Rutas protegidas por token. Cada una declara el PERMISO que exige
| (config/rbac.php). El superadministrador tiene bypass (Gate::before).
| El middleware 'can:' devuelve 403 si el usuario no tiene el permiso.
*/
Route::middleware('token')->group(function () {

    // --- Sin permiso específico: disponible para cualquier usuario autenticado ---
    Route::get('stats.php', [StatsController::class, 'handle']);       // dashboard
    Route::get('gating.php', [GatingController::class, 'handle']);     // candados de Meta
    Route::get('media.php', [MediaController::class, 'handle']);       // proxy de multimedia

    /*
     * Autorización del WEBSOCKET. Laravel registra /broadcasting/auth con el
     * middleware `web` (sesión + cookies), pero aquí la autenticación es por
     * TOKEN. Se registra dentro del grupo `token` para que el socket use el
     * mismo mecanismo que el resto de la API.
     */
    Route::post('broadcasting/auth', fn (Request $r) => Broadcast::auth($r));

    // --- Adjuntos (fuera de public/: solo se sirven autenticados) ---
    Route::get('attachment.php', [AttachmentController::class, 'handle'])
        ->middleware('can:helpdesk.access');

    // --- Helpdesk: TICKETS (núcleo del sistema) ---
    Route::match(['get', 'post'], 'tickets.php', [TicketsController::class, 'handle'])
        ->middleware('can:helpdesk.access');
    // Campos personalizados de ticket: rellenar valores (helpdesk.access); definir/borrar
    // el catálogo lo filtra el controlador con support.config.
    Route::match(['get', 'post'], 'ticket_fields.php', [\App\Http\Controllers\TicketFieldsController::class, 'handle'])
        ->middleware('can:helpdesk.access');

    // Centro de notificaciones del agente (campana): sus propios avisos.
    Route::match(['get', 'post'], 'notifications.php', [\App\Http\Controllers\NotificationsController::class, 'handle'])
        ->middleware('can:helpdesk.access');

    // --- Configuración de Soporte (categorías, respuestas): superadmin / encargado ---
    Route::match(['get', 'post', 'delete'], 'support_settings.php', [SupportSettingsController::class, 'handle'])
        ->middleware('can:support.config');
    // Canal correo: config del buzón (IMAP/SMTP) + probar conexión
    Route::match(['get', 'post'], 'email.php', [EmailAccountsController::class, 'handle'])
        ->middleware('can:support.config');
    // Correos bloqueados (banlist): sus correos entrantes no crean ticket
    Route::match(['get', 'post'], 'email_bans.php', [EmailBansController::class, 'handle'])
        ->middleware('can:support.config');
    // Plantillas de aviso (ticket creado / cerrado / asignado)
    Route::match(['get', 'post'], 'email_templates.php', [EmailTemplatesController::class, 'handle'])
        ->middleware('can:support.config');
    // Reglas automáticas de tickets (asignar/categorizar/priorizar al crearse)
    Route::match(['get', 'post'], 'ticket_rules.php', [TicketRulesController::class, 'handle'])
        ->middleware('can:support.config');
    // Prioridades configurables
    // Vistas guardadas de la bandeja (personales de cada agente).
    Route::match(['get', 'post'], 'ticket_views.php', [\App\Http\Controllers\TicketViewsController::class, 'handle'])
        ->middleware('can:helpdesk.access');
    // Etiquetas de ticket: LISTAR lo necesita cualquier agente (selector de la ficha);
    // gestionar el catálogo exige support.config, comprobado dentro del controlador.
    Route::match(['get', 'post'], 'ticket_labels.php', [\App\Http\Controllers\TicketLabelsController::class, 'handle'])
        ->middleware('can:helpdesk.access');
    Route::match(['get', 'post'], 'ticket_priorities.php', [TicketPrioritiesController::class, 'handle'])
        ->middleware('can:support.config');
    // Preguntas frecuentes del portal (crear/editar/ordenar/publicar)
    Route::match(['get', 'post'], 'faqs.php', [FaqsController::class, 'handle'])
        ->middleware('can:support.config');
    // Ajustes del ticket (estado por defecto, bloqueo de agentes, auto-cierre, seguridad)
    Route::match(['get', 'post'], 'ticket_settings.php', [TicketSettingsController::class, 'handle'])
        ->middleware('can:support.config');
    // Panel de FUNCIONES (interruptores del superadmin): encender/apagar lo que hoy está
    // repartido. El propio controlador exige superadmin (support.config es la puerta base).
    Route::match(['get', 'post'], 'features.php', [\App\Http\Controllers\FeaturesController::class, 'handle'])
        ->middleware('can:support.config');
    // Estado del planificador (cron): comando y si de verdad está corriendo
    Route::get('cron_status.php', [CronStatusController::class, 'handle'])
        ->middleware('can:support.config');
    // Horario de atención y festivos (la base del SLA)
    Route::match(['get', 'post'], 'business_hours.php', [BusinessHoursController::class, 'handle'])
        ->middleware('can:support.config');

    /*
     * Cuadrante de turnos. VER lo puede cualquiera del helpdesk (a todos les interesa
     * saber quién está de guardia); editarlo lo filtra el propio controlador con
     * support.config, porque es cosa del encargado.
     */
    Route::match(['get', 'post'], 'shifts.php', [ShiftsController::class, 'handle'])
        ->middleware('can:helpdesk.access');

    // Avisos de crones fallidos: apartado propio, fuera de la bandeja de soporte
    Route::match(['get', 'post'], 'cron_alerts.php', [CronAlertsController::class, 'handle'])
        ->middleware('can:helpdesk.access');

    // --- Helpdesk (bandeja / conversaciones) ---
    Route::match(['get', 'post'], 'conversations.php', [ConversationController::class, 'handle'])
        ->middleware('can:helpdesk.access');

    // Responder (texto/plantilla/interactivo y multimedia)
    Route::post('send.php', [SendController::class, 'handle'])->middleware('can:tickets.reply');
    Route::post('send_media.php', [SendMediaController::class, 'handle'])->middleware('can:tickets.reply');
    // Subir imagen en línea del editor (devuelve su URL firmada)
    Route::post('inline_media.php', [InlineImageController::class, 'upload'])->middleware('can:tickets.reply');
    // Memoria de respuestas efectivas: guardar ⭐ / sugerir parecidas / contar uso. Gestión
    // (listar/borrar) la filtra el controlador con support.config.
    Route::match(['get', 'post'], 'effective_responses.php', [\App\Http\Controllers\EffectiveResponsesController::class, 'handle'])
        ->middleware('can:tickets.reply');

    // --- Contactos y etiquetas ---
    Route::match(['get', 'post'], 'contacts.php', [ContactsController::class, 'handle'])
        ->middleware('can:contacts.access');
    // Organización de clientes (Grupo → Marca → Sede). Leer: cualquiera del helpdesk
    // (para el selector); crear/editar/borrar lo filtra el controlador (contacts.edit).
    Route::match(['get', 'post'], 'organizations.php', [OrganizationsController::class, 'handle'])
        ->middleware('can:contacts.access');
    Route::match(['get', 'post', 'delete'], 'labels.php', [LabelsController::class, 'handle'])
        ->middleware('can:contacts.access');
    Route::post('contact.php', [ContactController::class, 'handle'])
        ->middleware('can:contacts.edit');

    // --- Campañas y difusiones ---
    Route::match(['get', 'post', 'delete'], 'campaigns.php', [CampaignsController::class, 'handle'])
        ->middleware('can:campaigns.access');
    Route::match(['get', 'post', 'delete'], 'phonebooks.php', [PhonebooksController::class, 'handle'])
        ->middleware('can:campaigns.access');
    Route::match(['get', 'post', 'put', 'delete'], 'templates.php', [TemplatesController::class, 'handle'])
        ->middleware('can:templates.manage');
    Route::post('upload_media.php', [UploadMediaController::class, 'handle'])
        ->middleware('can:templates.manage');
    Route::match(['get', 'post', 'delete'], 'forms.php', [FormsController::class, 'handle'])
        ->middleware('can:forms.manage');

    // --- Automatizaciones / bots ---
    Route::match(['get', 'post', 'delete'], 'flows.php', [FlowsController::class, 'handle'])
        ->middleware('can:automations.manage');
    Route::get('responses.php', [ResponsesController::class, 'handle'])
        ->middleware('can:automations.access');

    // --- Administración ---
    Route::get('analytics.php', [AnalyticsController::class, 'handle'])
        ->middleware('can:analytics.view');
    // Informes del helpdesk (rendimiento: tickets, SLA, tiempos, por agente/categoría)
    Route::get('reports.php', [TicketReportsController::class, 'handle'])
        ->middleware('can:analytics.view');
    // Registro de acciones (auditoría). Solo superadmin (nadie más tiene activity.view).
    Route::get('activity.php', [\App\Http\Controllers\ActivityController::class, 'handle'])
        ->middleware('can:activity.view');
    Route::match(['get', 'post'], 'settings.php', [SettingsController::class, 'handle'])
        ->middleware('can:settings.manage');
    // Números de WhatsApp (opción B: enrutado por número). Solo superadmin.
    Route::match(['get', 'post'], 'whatsapp_numbers.php', [\App\Http\Controllers\WhatsAppNumbersController::class, 'handle'])
        ->middleware('can:settings.manage');
    Route::match(['get', 'post', 'delete'], 'users.php', [UsersController::class, 'handle'])
        ->middleware('can:users.manage');
    Route::match(['get', 'post', 'delete'], 'roles.php', [RolesController::class, 'handle'])
        ->middleware('can:users.manage');
});
