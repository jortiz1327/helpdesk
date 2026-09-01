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
            'rate'         => $this->rate($request),
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

    /** Valorar la atención (CSAT): nota 1..5 + comentario opcional. Token o pase. */
    protected function rate(Request $request)
    {
        if (!$request->isMethod('post')) return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);

        $code  = (string) $request->input('code');
        $email = $this->correoParaTicket($request, $code);
        if (!$email) return response()->json(['ok' => false, 'error' => 'Necesitas verificar tu correo', 'reauth' => true], 401);

        // Si la petición NO trae 'comment', se conserva el que hubiera (null = no tocar).
        [$ok, $error] = $this->portal->rate(
            $email, $code, (int) $request->input('score'),
            $request->has('comment') ? (string) $request->input('comment') : null,
        );
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

    /**
     * Página de VALORACIÓN (CSAT) por URL FIRMADA, sin login. Se llega desde las estrellas
     * del correo. `?score=N` (GET) guarda la nota; el `comment` (POST) guarda el comentario.
     * Renderiza una página mínima de agradecimiento con las estrellas y una caja de comentario.
     */
    public function ratePage(Request $request, int $ticket)
    {
        // SOLO el POST (un clic HUMANO) guarda. El ?score de un GET es únicamente una
        // PRE-SELECCIÓN visual: así los prefetchers de antivirus de correo (SafeLinks,
        // Mimecast…), que cargan todos los enlaces, NO falsean la valoración.
        $hint = (($h = (int) $request->query('score')) >= 1 && $h <= 5) ? $h : null;

        $aviso = null;
        $comentado = false;
        if ($request->isMethod('post')) {
            $score   = (($s = (int) $request->input('score')) >= 1 && $s <= 5) ? $s : null;
            $comment = $request->has('comment') ? (string) $request->input('comment', '') : null;
            if ($score !== null || $comment !== null) {
                [$ok, $err] = $this->portal->setRating($ticket, $score, $comment);
                if (!$ok) $aviso = $err;
            }
            $comentado = $comment !== null;
        }

        $r    = \Illuminate\Support\Facades\DB::table('ticket_ratings')->where('ticket_id', $ticket)->first(['score', 'comment']);
        $code = (string) (\Illuminate\Support\Facades\DB::table('tickets')->where('id', $ticket)->value('code') ?? '');
        $nota = (int) ($r->score ?? 0);
        $pintar = $nota > 0 ? $nota : (int) ($hint ?? 0);   // qué estrellas van doradas al pintar

        // Estrellas como FORMULARIOS POST (un clic = guarda), a la misma acción firmada.
        $exp    = now()->addDays(30);
        $accion = \Illuminate\Support\Facades\URL::signedRoute('portal.rate', ['ticket' => $ticket], $exp, false);
        $act    = e($accion);
        $estrellas = '';
        for ($n = 1; $n <= 5; $n++) {
            $estrellas .= '<form method="post" action="' . $act . '" style="display:inline;margin:0;padding:0;">'
                . '<input type="hidden" name="score" value="' . $n . '">'
                . '<button type="submit" title="' . $n . ' de 5" style="border:0;background:none;cursor:pointer;padding:0 3px;font-size:36px;line-height:1;color:'
                . ($n <= $pintar ? '#e0a63a' : '#d5dae2') . ';">&#9733;</button>'
                . '</form>';
        }

        return response($this->csatHtml($code, $nota, (string) ($r->comment ?? ''), $comentado, $aviso, $estrellas, $accion))
            ->header('Content-Type', 'text/html; charset=utf-8');
    }

    /** Página HTML mínima y autocontenida de la valoración. */
    private function csatHtml(string $code, int $nota, string $comment, bool $comentado, ?string $aviso, string $estrellas, string $accion): string
    {
        $c   = e($code);
        $com = e($comment);
        $act = e($accion);
        $titulo   = $nota > 0 ? '¡Gracias por tu valoración!' : '¿Cómo valorarías nuestra atención?';
        $cta      = $nota === 0 ? '<p style="font-size:13px;color:#4a5a72;margin:10px 0 0;">Pulsa una estrella para enviar tu valoración.</p>' : '';
        $avisoH   = $aviso ? '<p style="color:#c0392b;font-size:13px;margin:8px 0 0;">' . e($aviso) . '</p>' : '';
        $graciasC = $comentado && $aviso === null ? '<p style="color:#0f9d6b;font-size:13px;margin:8px 0 0;">Comentario guardado, ¡gracias!</p>' : '';

        return <<<HTML
<!doctype html><html lang="es"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Valoración · $c</title></head>
<body style="margin:0;background:#eef2f7;font-family:system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;color:#101a2c;">
<div style="max-width:460px;margin:8vh auto;background:#fff;border-radius:16px;box-shadow:0 8px 30px rgba(16,26,44,.1);padding:28px;text-align:center;">
  <div style="font-size:13px;color:#8494a8;">Incidencia $c</div>
  <h2 style="margin:6px 0 12px;font-size:20px;">$titulo</h2>
  <div style="display:flex;justify-content:center;gap:6px;">$estrellas</div>
  <div style="font-size:12px;color:#8494a8;margin-top:6px;">1 = muy insatisfecho · 5 = muy satisfecho</div>
  $cta
  $avisoH
  $graciasC
  <form method="post" action="$act" style="margin-top:16px;text-align:left;">
    <label style="display:block;font-size:13px;color:#4a5a72;margin-bottom:6px;">¿Quieres añadir un comentario? (opcional)</label>
    <textarea name="comment" rows="3" style="width:100%;box-sizing:border-box;padding:10px;border:1px solid #e3e8f0;border-radius:10px;font:inherit;font-size:14px;">$com</textarea>
    <button type="submit" style="margin-top:10px;width:100%;padding:11px;border:0;border-radius:10px;background:#2563eb;color:#fff;font-size:14px;font-weight:600;cursor:pointer;">Enviar comentario</button>
  </form>
  <div style="margin-top:16px;font-size:12px;color:#8494a8;">Atención al cliente · AEME Group</div>
</div></body></html>
HTML;
    }
}
