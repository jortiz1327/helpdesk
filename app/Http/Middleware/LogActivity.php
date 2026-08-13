<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Services\TokenService;
use Closure;
use Illuminate\Http\Request;

/*
 * REGISTRO DE ACCIONES (auditoría automática).
 *
 * En lugar de instrumentar botón por botón, este middleware observa CADA acción que
 * cambia datos en la API (POST/PUT/DELETE con éxito) y la traduce a una frase legible
 * mediante el mapa de abajo. Corre en `terminate()` para no añadir latencia a la
 * respuesta, y nunca rompe la petición aunque falle el registro.
 *
 * Cubre desde cerrar un ticket hasta crear un agente o iniciar sesión. Solo el
 * superadministrador consulta este registro (permiso activity.view).
 */
class LogActivity
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            $this->registrar($request, $response);
        } catch (\Throwable $e) {
            // Auditar nunca debe tumbar una petición.
        }
    }

    private function registrar(Request $request, $response): void
    {
        // Solo respuestas con éxito.
        $status = method_exists($response, 'getStatusCode') ? $response->getStatusCode() : 200;
        if ($status < 200 || $status >= 300) {
            return;
        }

        $endpoint = basename($request->path());

        // Inicio/cierre de sesión y cambio de contraseña: el usuario aún no viene por
        // el flujo normal (login no autentica por token), así que se resuelve aparte.
        if ($endpoint === 'auth.php') {
            $this->registrarSesion($request);
            return;
        }

        // El resto: solo acciones que cambian datos, de un usuario autenticado.
        if (! in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return;
        }
        $user = $request->user();
        if (! $user) {
            return;
        }

        $mapa = self::MAPA[$endpoint] ?? null;
        if ($mapa === null) {
            return;   // endpoint sin interés (solo lectura, proxies, websocket…)
        }

        $action = (string) ($request->query('action') ?? $request->input('action') ?? '');
        if (in_array($action, self::IGNORAR, true)) {
            return;
        }

        [$section, $summary] = $this->describir($endpoint, $mapa, $action, $request);

        $this->guardar($user->id, $user->name ?: $user->email, $section, $action ?: strtolower($request->getMethod()), $summary, $this->sujeto($request), $request);
    }

    /* Login (por email) · logout y cambio de contraseña (por el token de la cabecera). */
    private function registrarSesion(Request $request): void
    {
        if (! $request->isMethod('post')) {
            return;
        }
        $action = (string) $request->query('action', '');

        if ($action === 'login') {
            $email = trim((string) $request->input('email'));
            $u = $email !== '' ? \App\Models\User::where('email', $email)->first() : null;
            if ($u) {
                $this->guardar($u->id, $u->name ?: $u->email, 'Sesión', 'login', 'Inició sesión', null, $request);
            }
            return;
        }

        // logout / change → el usuario va en el token de la cabecera.
        $u = TokenService::verify($request->bearerToken());
        if (! $u) {
            return;
        }
        if ($action === 'logout') {
            $this->guardar($u->id, $u->name ?: $u->email, 'Sesión', 'logout', 'Cerró sesión', null, $request);
        } elseif ($action === 'change') {
            $this->guardar($u->id, $u->name ?: $u->email, 'Sesión', 'change', 'Cambió su contraseña', null, $request);
        }
    }

    private function guardar(?int $uid, ?string $uname, string $section, string $action, string $summary, ?string $subject, Request $request): void
    {
        ActivityLog::create([
            'user_id'    => $uid,
            'user_name'  => $uname,
            'section'    => $section,
            'action'     => mb_substr($action, 0, 60),
            'summary'    => mb_substr($summary, 0, 300),
            'subject'    => $subject ? mb_substr($subject, 0, 120) : null,
            'method'     => $request->getMethod(),
            'ip'         => $request->ip(),
            'created_at' => now(),
        ]);
    }

    /* Devuelve [apartado, frase]. */
    private function describir(string $endpoint, array $mapa, string $action, Request $request): array
    {
        $section = $mapa['section'];

        // Acciones con frase propia (las más habituales).
        if (isset($mapa['actions'][$action])) {
            return [$section, $mapa['actions'][$action]];
        }

        // Cambio de estado de un ticket: la frase depende del estado destino.
        if ($endpoint === 'tickets.php' && $action === 'status') {
            $st = (string) $request->input('status');
            return [$section, self::ESTADO[$st] ?? 'Cambió el estado de un ticket'];
        }

        // Organización: la frase depende del nivel (grupo / marca / sede).
        if ($endpoint === 'organizations.php') {
            $nivel = ['grupo' => 'un grupo', 'marca' => 'una marca', 'sede' => 'una sede'][$request->input('level')] ?? 'la organización';
            $verbo = $action === 'delete' ? 'Eliminó' : ($request->input('id') ? 'Editó' : 'Creó');
            return [$section, "$verbo $nivel"];
        }

        $noun = $mapa['noun'] ?? 'un elemento';

        // Borrado (por método o por acción).
        if ($request->getMethod() === 'DELETE' || $action === 'delete' || $action === 'remove') {
            return [$section, "Eliminó $noun"];
        }
        // Config (ajustes, horario, cuadrante…): siempre «Actualizó».
        if (($mapa['kind'] ?? 'entity') === 'config') {
            return [$section, "Actualizó $noun"];
        }
        // Entidad: crear vs editar según venga id.
        $verbo = ($request->input('id') || $request->input('uuid')) ? 'Editó' : 'Creó';
        return [$section, "$verbo $noun"];
    }

    /* Referencia corta de sobre qué actuó: TK-… › #id › contacto. */
    private function sujeto(Request $request): ?string
    {
        $code = $request->input('code') ?? $request->query('code');
        if (is_string($code) && preg_match('/^TK-/i', $code)) {
            return strtoupper($code);
        }
        foreach (['id', 'ticket_id', 'template_id', 'campaign_id'] as $k) {
            $v = $request->input($k) ?? $request->query($k);
            if (is_scalar($v) && $v !== '' && $v !== '0') {
                return '#' . $v;
            }
        }
        $cid = $request->input('contact_id') ?? $request->query('contact_id');
        if (is_scalar($cid) && $cid !== '') {
            return 'contacto #' . $cid;
        }
        return null;
    }

    /* Estado destino → frase. */
    private const ESTADO = [
        'cerrado'              => 'Cerró un ticket',
        'resuelto'             => 'Resolvió un ticket',
        'en_progreso'          => 'Puso un ticket en progreso',
        'esperando_respuesta'  => 'Dejó un ticket esperando respuesta',
        'abierto'              => 'Reabrió un ticket',
        'nuevo'                => 'Marcó un ticket como nuevo',
    ];

    /* Sub-acciones que llegan por POST pero son de solo lectura → no ensucian el registro. */
    private const IGNORAR = ['list', 'detail', 'stats', 'meta', 'agents', 'history', 'canned', 'mergeable', 'pdf', 'schema', 'counts', 'get', 'me', 'test'];

    /*
     * Mapa endpoint → apartado + frases. 'actions' cubre acciones ricas; 'kind'=>'config'
     * fuerza «Actualizó»; el resto de entidades hace crear/editar/eliminar según método
     * e id. Los endpoints no listados no se registran.
     */
    private const MAPA = [
        // --- Helpdesk ---
        'tickets.php' => ['section' => 'Helpdesk', 'noun' => 'un ticket', 'actions' => [
            'reply'  => 'Respondió a un ticket',
            'note'   => 'Añadió una nota interna',
            'labels' => 'Cambió las etiquetas de un ticket',
            'assign' => 'Asignó un ticket',
            'create' => 'Creó un ticket',
            'delete' => 'Eliminó un ticket',
            'merge'  => 'Fusionó tickets',
            'bulk'   => 'Acción masiva sobre tickets',
            'unlock' => 'Desbloqueó un ticket',
            // 'status' se resuelve aparte (según el estado destino).
        ]],
        'send.php'       => ['section' => 'Helpdesk', 'noun' => 'un mensaje'],
        'send_media.php' => ['section' => 'Helpdesk', 'noun' => 'un adjunto'],

        // --- Contactos ---
        'contacts.php' => ['section' => 'Contactos', 'noun' => 'contactos', 'actions' => [
            'merge'          => 'Fusionó contactos',
            'bulk_label'     => 'Etiquetó contactos en bloque',
            'bulk_phonebook' => 'Movió contactos de agenda',
            'set_optout'     => 'Cambió el consentimiento de un contacto',
        ]],
        'contact.php' => ['section' => 'Contactos', 'noun' => 'un contacto'],
        'labels.php'  => ['section' => 'Contactos', 'noun' => 'una etiqueta'],

        // --- Organización ---
        'organizations.php' => ['section' => 'Organización'],

        // --- Turnos ---
        'shifts.php' => ['section' => 'Turnos', 'noun' => 'el cuadrante de turnos', 'kind' => 'config'],

        // --- Campañas ---
        'campaigns.php' => ['section' => 'Campañas', 'noun' => 'una campaña', 'actions' => [
            'run'    => 'Lanzó una campaña',
            'cancel' => 'Canceló una campaña',
        ]],
        'phonebooks.php'   => ['section' => 'Campañas', 'noun' => 'una agenda'],
        'templates.php'    => ['section' => 'Campañas', 'noun' => 'una plantilla'],
        'forms.php'        => ['section' => 'Campañas', 'noun' => 'un formulario'],
        'flows.php'        => ['section' => 'Campañas', 'noun' => 'un flujo'],
        'upload_media.php' => ['section' => 'Campañas', 'noun' => 'un archivo'],

        // --- Configuración ---
        'support_settings.php'  => ['section' => 'Configuración', 'noun' => 'los ajustes de soporte',  'kind' => 'config'],
        'ticket_settings.php'   => ['section' => 'Configuración', 'noun' => 'los ajustes del ticket',   'kind' => 'config'],
        'business_hours.php'    => ['section' => 'Configuración', 'noun' => 'el horario laboral',        'kind' => 'config'],
        'settings.php'          => ['section' => 'Configuración', 'noun' => 'los ajustes generales',     'kind' => 'config'],
        'ticket_priorities.php' => ['section' => 'Configuración', 'noun' => 'una prioridad'],
        'ticket_rules.php'      => ['section' => 'Configuración', 'noun' => 'una regla automática'],
        'faqs.php'              => ['section' => 'Configuración', 'noun' => 'una entrada de la base de conocimiento'],
        'email.php'             => ['section' => 'Configuración', 'noun' => 'una cuenta de correo'],
        'email_bans.php'        => ['section' => 'Configuración', 'noun' => 'un bloqueo de correo'],
        'email_templates.php'   => ['section' => 'Configuración', 'noun' => 'una plantilla de correo'],

        // --- Administración ---
        'users.php' => ['section' => 'Administración', 'noun' => 'un usuario'],
        'roles.php' => ['section' => 'Administración', 'noun' => 'un rol'],
    ];
}
