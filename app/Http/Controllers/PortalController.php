<?php

namespace App\Http\Controllers;

use App\Services\PortalService;
use Illuminate\Http\Request;

/**
 * PORTAL PÚBLICO. Rutas SIN token de agente: la identidad es el correo del cliente
 * verificado con un código, y el «pase» viaja en la cabecera X-Portal-Token.
 *
 * Acciones abiertas: request-code, verify-code, categories, faqs, info, faq-view,
 *                     faq-vote, ticket-status (estado por número, solo lectura),
 *                     create (público, devuelve token del ticket).
 * Por ticket (pase O token del ticket): ticket, reply, resolve.
 * Con pase completo (código): me, tickets.
 */
class PortalController extends Controller
{
    public function __construct(protected PortalService $portal) {}

    public function handle(Request $request)
    {
        $accion = $request->query('action', '');

        return match ($accion) {
            'request-code' => $this->requestCode($request),
            'verify-code'  => $this->verifyCode($request),
            'categories'   => response()->json(['ok' => true, 'categories' => $this->portal->categories()]),
            // FAQ del portal: públicas. Listar las publicadas, sumar una vista, votar.
            'faqs'         => response()->json(['ok' => true, 'faqs' => $this->portal->faqs()]),
            'info'         => response()->json(['ok' => true, 'info' => $this->portal->info()]),
            'faq-view'     => $this->faqView($request),
            'faq-vote'     => $this->faqVote($request),
            // Estado por número (solo lectura, público): fase + fechas, sin nada sensible.
            'ticket-status' => $this->ticketStatus($request),
            // «Mis incidencias» y «yo» exponen TODO lo del correo: exigen pase (código).
            'me'           => $this->conPase($request, fn ($email) =>
                                    response()->json(['ok' => true, 'email' => $email])),
            'tickets'      => $this->conPase($request, fn ($email) =>
                                    response()->json(['ok' => true, 'tickets' => $this->portal->myTickets($email)])),
            // Crear es PÚBLICO (sin código): baja la fricción. Devuelve un token que
            // abre solo ese ticket. Ver/responder/resolver aceptan pase O ese token.
            'create'       => $this->create($request),
            'ticket'       => $this->ticket($request),
            'reply'        => $this->reply($request),
            'resolve'      => $this->resolve($request),
            default        => response()->json(['ok' => false, 'error' => 'Acción no válida'], 400),
        };
    }

    /** Envuelve una acción que exige PASE completo del correo (código): o 401. */
    protected function conPase(Request $request, \Closure $fn)
    {
        $token = $request->header('X-Portal-Token') ?: $request->bearerToken();
        $email = $this->portal->emailFromToken($token);
        if (!$email) {
            return response()->json(['ok' => false, 'error' => 'Sesión caducada', 'reauth' => true], 401);
        }
        return $fn($email);
    }

    /**
     * Correo autorizado para UN ticket concreto, por dos vías:
     *   1) el token que abre solo ese ticket (el que se dio al crearlo), o
     *   2) el pase completo del correo (cuya propiedad se comprueba aguas abajo,
     *      porque cada método filtra por `code + email`).
     * Devuelve null si no hay ninguna → 401 y la UI pide el código.
     */
    protected function correoParaTicket(Request $request, string $code): ?string
    {
        $porToken = $this->portal->emailFromTicketToken($request->header('X-Ticket-Token'), $code);
        if ($porToken) return $porToken;

        $pass = $request->header('X-Portal-Token') ?: $request->bearerToken();
        return $this->portal->emailFromToken($pass);
    }

    protected function requestCode(Request $request)
    {
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);

        [$ok, $error] = $this->portal->requestCode((string) $request->input('email'), $request->ip());
        // Aunque falle el envío se devuelve ok si el correo era válido: no se revela
        // nada del buzón destino. Los errores reales (correo inválido, antispam) sí salen.
        if (!$ok) return response()->json(['ok' => false, 'error' => $error], 429);
        return response()->json(['ok' => true]);
    }

    protected function verifyCode(Request $request)
    {
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);

        [$ok, $res] = $this->portal->verifyCode(
            (string) $request->input('email'), (string) $request->input('code'), $request->ip(),
        );
        if (!$ok) return response()->json(['ok' => false, 'error' => $res], 422);
        return response()->json(['ok' => true, 'token' => $res['token'], 'email' => $res['email']]);
    }

    /** Suma una vista a una FAQ (analítica; sin pase, tolerante a fallos). */
    protected function faqView(Request $request)
    {
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
        $this->portal->faqView((int) $request->input('id'));
        return response()->json(['ok' => true]);
    }

    /** Registra un voto 👍/👎 en una FAQ (sin pase). */
    protected function faqVote(Request $request)
    {
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
        $this->portal->faqVote((int) $request->input('id'), filter_var($request->input('helpful'), FILTER_VALIDATE_BOOLEAN));
        return response()->json(['ok' => true]);
    }

    /** Consulta de estado por número (solo lectura, sin auth). */
    protected function ticketStatus(Request $request)
    {
        $d = $this->portal->statusByCode((string) $request->query('code'));
        if (!$d) return response()->json(['ok' => false, 'error' => 'No encontramos ninguna incidencia con ese número'], 404);
        return response()->json(['ok' => true, 'status' => $d]);
    }

    protected function ticket(Request $request)
    {
        $code  = (string) $request->query('code');
        $email = $this->correoParaTicket($request, $code);
        if (!$email) return response()->json(['ok' => false, 'error' => 'Necesitas verificar tu correo', 'reauth' => true], 401);

        $d = $this->portal->ticketDetail($email, $code);
        if (!$d) return response()->json(['ok' => false, 'error' => 'Incidencia no encontrada'], 404);
        return response()->json(['ok' => true, 'ticket' => $d]);
    }

    /**
     * Crear incidencia SIN código (público). Se toma el correo del formulario, se crea
     * el ticket y se devuelve un TOKEN que abre solo ese ticket, para que el cliente
     * lo vea al instante sin pedirle nada.
     */
    protected function create(Request $request)
    {
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);

        $email = mb_strtolower(trim((string) $request->input('email')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'Escribe un correo válido para poder avisarte'], 422);
        }

        [$ok, $error, $code] = $this->portal->createTicket($email, [
            'subject'     => $request->input('subject'),
            'body'        => $request->input('body'),
            'category_id' => $request->input('category_id'),
            'name'        => $request->input('name'),
        ], (array) $request->file('files', []));
        if (!$ok) return response()->json(['ok' => false, 'error' => $error], 422);

        return response()->json(['ok' => true, 'code' => $code, 'token' => $this->portal->makeTicketToken($email, $code)]);
    }

    protected function reply(Request $request)
    {
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);

        $code  = (string) $request->input('code');
        $email = $this->correoParaTicket($request, $code);
        if (!$email) return response()->json(['ok' => false, 'error' => 'Necesitas verificar tu correo', 'reauth' => true], 401);

        [$ok, $error] = $this->portal->reply(
            $email, $code, (string) $request->input('body'), (array) $request->file('files', []),
        );
        if (!$ok) return response()->json(['ok' => false, 'error' => $error], 422);
        return response()->json(['ok' => true]);
    }

    protected function resolve(Request $request)
    {
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);

        $code  = (string) $request->input('code');
        $email = $this->correoParaTicket($request, $code);
        if (!$email) return response()->json(['ok' => false, 'error' => 'Necesitas verificar tu correo', 'reauth' => true], 401);

        [$ok, $error] = $this->portal->resolve($email, $code);
        if (!$ok) return response()->json(['ok' => false, 'error' => $error], 422);
        return response()->json(['ok' => true]);
    }

    /**
     * Sirve un adjunto de un ticket por URL FIRMADA (sin pase): la firma es la
     * autorización, y solo se firman los adjuntos de tickets que ya se comprobó que
     * son del correo del cliente (ver PortalService::ticketDetail). Igual que las
     * imágenes en línea del correo. Va FUERA del `handle()` porque no lleva pase.
     */
    public function file(int $id)
    {
        $res = $this->portal->serveFile($id);
        if (!$res) abort(404);
        [$path, $row] = $res;

        $inline = str_starts_with((string) $row->mime, 'image/');
        return response()->file($path, [
            'Content-Type'           => $row->mime ?: 'application/octet-stream',
            'Content-Disposition'    => ($inline ? 'inline' : 'attachment') . '; filename="' . addslashes($row->name) . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
