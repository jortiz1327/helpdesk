<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use App\Services\ConfigActivity;
use App\Services\TicketActivity;
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
        // Tickets: foto de cómo estaban ANTES, para poder contar después QUÉ cambió.
        // Va en los atributos de la petición: terminate() corre en otra instancia.
        try {
            $endpoint = basename($request->path());
            $action   = (string) ($request->query('action') ?? $request->input('action') ?? '');
            if ($endpoint === 'tickets.php'
                && in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)
                && ! in_array($action, [...TicketActivity::RUIDO, ...self::IGNORAR], true)) {
                $request->attributes->set('activity_antes', TicketActivity::snapshot(TicketActivity::idsDe($request)));
            }
            // Contactos y configuración: lo mismo con las tablas que toca cada endpoint.
            if (ConfigActivity::cubre($endpoint) && ConfigActivity::escribe($endpoint, $request, $action)
                && ! in_array($action, self::IGNORAR, true)) {
                $request->attributes->set('activity_antes', ConfigActivity::snapshot($endpoint, $request));
            }
        } catch (\Throwable $e) {
            report($e);   // auditar nunca debe tumbar una petición
        }
        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        try {
            $this->registrar($request, $response);
        } catch (\Throwable $e) {
            // Auditar nunca debe tumbar una petición, pero sí dejar rastro en el log.
            report($e);
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

        // Contactos y configuración: qué se creó, borró o cambió, campo a campo. Si la
        // acción no cambió nada (guardar sin tocar), no se apunta.
        if (ConfigActivity::cubre($endpoint)) {
            $this->registrarConfig($endpoint, $request, $response);
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

        // Respuestas 200 que en realidad fallaron ({ok:false}): no pasó nada que auditar.
        $json = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
        if (($json['ok'] ?? true) === false) {
            return;
        }

        // Tickets: frase con el código, referencia con el asunto y el detalle del cambio.
        if ($endpoint === 'tickets.php') {
            if (in_array($action, TicketActivity::RUIDO, true)) {
                return;   // abrir/cerrar la ficha, latidos… no son acciones del agente
            }
            $d = TicketActivity::describir($action, $request, (array) $request->attributes->get('activity_antes', []), $json);
            if ($d === null && in_array($action, ['assign', 'status', 'priority', 'category', 'labels'], true)) {
                return;   // «cambio» que no cambió nada (mismo valor)
            }
            if ($d !== null) {
                [$summary, $subject, $details] = $d;
                $this->guardar($user->id, $user->name ?: $user->email, $mapa['section'], $action, $summary, $subject ?? $this->sujeto($request), $request, $details);
                return;
            }
        }

        [$section, $summary] = $this->describir($endpoint, $mapa, $action, $request);

        $this->guardar($user->id, $user->name ?: $user->email, $section, $action ?: strtolower($request->getMethod()), $summary, $this->sujeto($request), $request);
    }

    private function registrarConfig(string $endpoint, Request $request, $response): void
    {
        $user   = $request->user();
        $action = (string) ($request->query('action') ?? $request->input('action') ?? '');
        $antes  = $request->attributes->get('activity_antes');
        if (! $user || $antes === null || in_array($action, self::IGNORAR, true)) {
            return;   // sin foto previa = no era una escritura
        }
        $json = method_exists($response, 'getData') ? (array) $response->getData(true) : [];
        if (($json['ok'] ?? true) === false) {
            return;
        }
        $d = ConfigActivity::describir($endpoint, $action, $request, (array) $antes, $json);
        if ($d === null) {
            return;
        }
        [$summary, $subject, $details] = $d;
        $this->guardar($user->id, $user->name ?: $user->email, ConfigActivity::ENDPOINTS[$endpoint]['section'],
            $action ?: strtolower($request->getMethod()), $summary, $subject, $request, $details);
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

    private function guardar(?int $uid, ?string $uname, string $section, string $action, string $summary, ?string $subject, Request $request, array $details = []): void
    {
        ActivityLog::create([
            'user_id'    => $uid,
            'user_name'  => $uname,
            'section'    => $section,
            'action'     => mb_substr($action, 0, 60),
            'summary'    => mb_substr($summary, 0, 300),
            'subject'    => $subject ? mb_substr($subject, 0, 200) : null,
            'details'    => $details ? json_encode(array_values($details), JSON_UNESCAPED_UNICODE) : null,
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
        'planificado'          => 'Planificó un ticket',
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
            // 'status' se resuelve aparte (según el estado destino). Lo normal es que
            // TicketActivity dé la frase con el detalle; estas quedan de reserva.
        ]],
        'send.php'       => ['section' => 'Helpdesk', 'noun' => 'un mensaje'],
        'send_media.php' => ['section' => 'Helpdesk', 'noun' => 'un adjunto'],

        // Contactos y Configuración: los describe ConfigActivity (con el detalle del cambio).

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

        // --- Gestión de garantías ---
        // devices.php no se apunta: solo consulta (la búsqueda va por POST pero no cambia nada).
        'zkong_servers.php' => ['section' => 'Garantías', 'noun' => 'un servidor ZKONG'],
        'warranties.php'    => ['section' => 'Garantías', 'noun' => 'una garantía', 'actions' => [
            'note' => 'Añadió un seguimiento a una garantía',
        ]],

        // --- Administración ---
        'users.php' => ['section' => 'Administración', 'noun' => 'un usuario'],
        'roles.php' => ['section' => 'Administración', 'noun' => 'un rol'],
    ];
}
