<?php

namespace App\Services\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Sugerencias de respuesta para un ticket (horario humano: la IA NO contesta, propone).
 *
 * «Entrenarla» no es reentrenar un modelo: cada sugerencia se escribe con
 *   1. las instrucciones de la casa (este archivo),
 *   2. las FAQs publicadas que mejor encajan con lo que pregunta el cliente,
 *   3. las correcciones que los agentes escribieron cuando una sugerencia salió mal.
 * Así, cada corrección guardada mejora las siguientes respuestas, con cualquier proveedor.
 */
class AiSuggestionService
{
    /** Marca que devuelve el modelo cuando no tiene base para contestar. */
    public const NO_SABE = '[[NO_SABE]]';

    /** Marca que sustituye a un audio de WhatsApp en la conversación, y el texto fijo con el que se contesta si es lo único que llegó. */
    public const MARCA_AUDIO = '[audio]';
    public const TEXTO_AUDIO = "Lo sentimos, pero ahora mismo no podemos escuchar este audio. ¿Podría escribirnos su consulta por mensaje? Muchas gracias.\n\nAtención al cliente · AEME Group";

    /** Qué hacer según el estado de las antenas (decisión de Juan, 16-nov). El estado es el de AHORA, no el del día del ticket. */
    private const REGLA_ANTENAS = "- Según las antenas:\n"
        . "  · NUNCA afirmes que «el sistema no está caído», que «funciona correctamente» ni que no hay ninguna incidencia: las antenas en línea solo prueban que las antenas responden, no el estado del servicio, y puede haber una incidencia general que solo conoce el equipo. Si el cliente pregunta por una incidencia o caída general, di lo que sí ves (las antenas) y que el equipo le informará del estado de la incidencia.\n"
        . "  · EXCEPCIÓN MUY ACOTADA: solo si el cliente dice literalmente que UN cartel o etiqueta muestra «fuera de servicio» (u otro aviso de error en pantalla) y no da ningún código ni posición: pídele ese código o posición y si afecta solo a esa o a todas (máximo dos datos), sin pasos de forzar menú. En CUALQUIER otro caso (etiquetas que no cambian, no se actualizan, se cambian solas, «no funcionan», «sigue caído», menú que no cambia, etiquetas actualizadas que quiere revisar) NO pidas etiqueta: aplica las reglas de abajo.\n"
        . "  · TODAS CON CONEXIÓN (y ya sabes qué etiqueta o menú falla): no derives ni digas que «un compañero lo revisará». Cuéntale que las antenas están en línea y pregúntale si en el servicio actual se ve ya correctamente (si no, qué etiqueta o menú sigue mal). Si ya cuenta que SIGUE sin cambiar y todavía no ha forzado el menú, DALE EL PASO: que fuerce el menú de hoy desde Ruedas de menús > Forzar menú (elegir el menú y el servicio actuales; si falla solo una isleta, «Forzar solo una isleta»), o una etiqueta suelta desde Etiquetas > Forzar etiqueta (una vez cada 5 minutos). Hace falta el permiso «Forzar menús»; si no ve el botón, que se lo pida a un administrador de su hotel. Después pregunta si ya cambió. Deriva solo si ya forzó y sigue igual.\n"
        . "  · EXCEPCIÓN QUE SE DERIVA: si cuenta incidencias REPETIDAS (de varios días o servicios) o etiquetas que «se cambian solas» en mitad del servicio, NO es cuestión de forzar el menú ni de las antenas (suele ser configuración de sus menús): no des pasos de forzar menú. Confirma lo que ves de las antenas si procede y deriva con la frase corta de que el equipo lo revisará.
"
        . "  · ALGUNA SIN CONEXIÓN: dile cuáles y que debe revisarlo su personal técnico o el equipo de mantenimiento del hotel. NO le pidas reiniciar, desconectar ni tocar nada tú. No des MAC ni IP de antenas.\n";

    private const MAX_FAQS = 4;
    private const MAX_EJEMPLOS = 4;
    private const MAX_MENSAJES = 10;

    /** Solo el laboratorio: contesta como si la conversación terminara en este mensaje (ignora lo posterior). */
    private ?int $hasta = null;

    private bool $anonimizar = false;
    private bool $evalApi = false;
    private string $nombreCliente = '';
    /** Solo evaluación: ticket que NO puede encontrarse a sí mismo en el conocimiento (leave-one-out). */
    private ?int $excluirTicket = null;
    /** Nombre de hotel que encaja con varios hoteles del panel (se rellena en contextoHotel). */
    private ?string $hotelAmbiguo = null;
    /** Lo que se supo del correo y del hotel en la última consulta a AdminHoteles (para decidir en código). */
    private ?string $estadoCorreo = null;
    /** El cliente nombra un sitio y el listado de hoteles del panel (consultado, no caído) no lo conoce: quizá no sea un hotel. */
    private bool $hotelNoEncontrado = false;
    private bool $hotelLocalizado = false;
    private bool $hotelSinApi = false;
    /** Lo que hizo la IA en esta sugerencia, paso a paso (se guarda en `sources.traza` y se enseña al agente en el panel). */
    private array $traza = [];
    /** A qué categoría de la app (soporte, garantías, pedidos) debe llevarlo una persona, si la IA derivó. Nota interna para el agente. */
    private ?array $derivarA = null;
    /** Estilo pedido por el agente para esta sugerencia: ['largo' => corta|detallada, 'tono' => cercano|formal]. Solo cambia la redacción. */
    private array $estilo = [];
    public const ESTILOS = ['largo' => ['corta', 'detallada'], 'tono' => ['cercano', 'formal']];
    /**
     * Departamentos a los que la IA puede derivar (los válidos, decididos por Juan el 16-nov; la IA no inventa otros).
     * 'nombre' es lo que se le dice al cliente; 'categoria' y 'categoria_key' son la categoría de ticket de la app a la que lo lleva el
     * agente (y a la que lo asignará la fase 3). Postventa = categoría «Pedidos y facturas». Proyectos aún no tiene categoría ni reglas:
     * mientras tanto va a Soporte y la IA no lo usa.
     */
    public const DEPARTAMENTOS = [
        'soporte'   => ['nombre' => 'Soporte',   'categoria' => 'Soporte',            'categoria_key' => 'soporte'],
        'garantias' => ['nombre' => 'Garantías', 'categoria' => 'Garantías',          'categoria_key' => 'garantias'],
        'postventa' => ['nombre' => 'Postventa', 'categoria' => 'Pedidos y facturas', 'categoria_key' => 'pedidos_facturas'],
        'proyectos' => ['nombre' => 'Proyectos', 'categoria' => 'Soporte',            'categoria_key' => 'soporte'],
    ];

    /** Lo que se le dice al cliente al derivar. Hoy el agente asigna el ticket a mano (será derivado); en la fase 3, al asignarlo solo, «ha sido derivado» (ai.derivacion_asignada). */
    public static function fraseDerivacion(string $departamento): string
    {
        try {
            $asignada = (bool) config('ai.derivacion_asignada', false);
        } catch (Throwable) {
            $asignada = false;   // sin la aplicación cargada (tests unitarios puros)
        }
        $verbo = $asignada ? 'ha sido derivada' : 'será derivada';
        return 'Gracias por su mensaje. Hemos registrado la incidencia y ' . $verbo . ' al departamento de ' . $departamento . ', que la revisará.';
    }

    /** Si el borrador es la frase corta de siempre y la IA marcó un departamento, se sustituye por la del departamento. */
    public static function aplicarFraseDerivacion(string $texto, ?array $dest): string
    {
        if ($dest && preg_match('/^\s*Gracias por su mensaje\.\s*Hemos registrado la incidencia y el equipo la revisará\.?\s*$/u', $texto)) {
            return self::fraseDerivacion($dest['departamento']);
        }
        return $texto;
    }

    /** Apunta un paso en la traza. $ok: true = bien, false = problema, null = informativo. */
    /** Quien llama (el panel del ticket) recibe cada paso al momento: así el agente ve lo que va haciendo la IA. */
    private $alPaso = null;

    public function alPaso(?callable $f): void
    {
        $this->alPaso = $f;
    }

    private function paso(string $k, ?bool $ok, string $texto, ?array $datos = null): void
    {
        $p = ['k' => $k, 'ok' => $ok, 'texto' => $texto] + ($datos !== null ? ['datos' => $datos] : []);
        $this->traza[] = $p;
        if ($this->alPaso) { ($this->alPaso)(['paso' => $p]); }
    }

    /** Aviso pasajero de lo que está haciendo ahora (no se guarda). */
    private function avisar(string $texto): void
    {
        if ($this->alPaso) { ($this->alPaso)(['hace' => $texto]); }
    }

    private function pasoAntenas(string $buffet, array $an): void
    {
        $caidas = array_values(array_filter($an, fn ($a) => ! $a['online']));
        $en = count($an) - count($caidas);
        $this->paso('antenas', ! $caidas, 'Antenas de ' . ($buffet ?: 'el hotel') . ': ' . count($an) . ' localizadas · ' . $en . ' en línea'
            . ($caidas ? ' · sin conexión: ' . implode(', ', array_map(fn ($a) => $a['name'], $caidas)) : ''),
            ['buffet' => $buffet, 'consultado' => $an[0]['consultado'] ?? null, 'en_vivo' => $an[0]['en_vivo'] ?? null,
             'lista' => array_map(fn ($a) => ['nombre' => $a['name'], 'en_linea' => (bool) $a['online'], 'caida_desde' => $a['offline_since'], 'etiquetas' => $a['esl']], $an)]);
    }

    public function __construct(protected AiProvider $ai, protected ?AiKnowledge $knowledge = null)
    {
        $this->knowledge ??= new AiKnowledge();
    }

    /** Interruptor del panel (ajuste) Y clave puesta. Sin las dos, no sale ningún dato. */
    public function enabled(): bool
    {
        return Setting::get('ai_suggestions_enabled', '0') === '1' && $this->ai->configured();
    }

    /** ¿Hay clave del proveedor activo? (no dice cuál es) */
    public function configurado(): bool
    {
        return $this->ai->configured();
    }

    /**
     * Datos del panel de Ajustes: interruptor, proveedor, acierto medido con las valoraciones
     * de los agentes, conocimiento indexado y las últimas correcciones. Nunca lleva la clave.
     */
    /** Estado de cada API/herramienta que usa la IA, para Ajustes (últimas 24 h; ver ApiMonitor). */
    private function estadoApis(): array
    {
        $prov = (string) config('ai.provider');
        $apis = [
            ['id' => 'adminhoteles', 'nombre' => 'AdminHoteles (panel de hoteles)', 'uso' => 'Hotel, correo, antenas, etiquetas y posiciones', 'configurada' => app(HotelsApi::class)->configured()],
            ['id' => 'proveedor', 'nombre' => 'Proveedor de IA (' . ucfirst($prov) . ')', 'uso' => 'Redacta las sugerencias y, a veces, busca en la memoria', 'configurada' => $this->ai->configured()],
            ['id' => 'memoria', 'nombre' => 'Memoria (manuales y casos)', 'uso' => 'Búsqueda propia, sin salir del servidor', 'configurada' => true],
        ];
        return array_map(fn ($a) => $a + ['umbral_lenta_s' => ApiMonitor::LENTA[$a['id']] ?? 6.0] + ApiMonitor::resumen($a['id']), $apis);
    }

    public function panel(): array
    {
        $prov = (string) config('ai.provider');
        $s = DB::table('ai_suggestions');
        $cuenta = fn (string $estado) => (clone $s)->where('status', $estado)->count();
        $arriba = (clone $s)->where('rating', 'up')->count();
        $abajo = (clone $s)->where('rating', 'down')->count();

        return [
            'enabled'    => Setting::get('ai_suggestions_enabled', '0') === '1',
            'configured' => $this->ai->configured(),
            'provider'   => $prov,
            'model'      => (string) config("ai.providers.$prov.model"),
            'stats'      => [
                'total' => (clone $s)->count(), 'ready' => $cuenta('ready'), 'abstained' => $cuenta('abstained'), 'failed' => $cuenta('failed'),
                'up' => $arriba, 'down' => $abajo, 'used' => (clone $s)->whereNotNull('used_at')->count(),
                // Acierto = útiles / valoradas. Solo tiene sentido con algunas valoraciones.
                'accuracy' => ($arriba + $abajo) > 0 ? round($arriba / ($arriba + $abajo) * 100) : null,
            ],
            'usage'      => $this->consumo(),
            'budget'     => $this->presupuesto(),
            'apps_ajenas' => self::ajenas(),
            'hotels_api' => app(HotelsApi::class)->configured() ? HotelsApi::estadoPanel() : null,
            'apis'       => $this->estadoApis(),
            'knowledge'  => [
                'tickets' => DB::table('ai_documents')->where('source_type', 'ticket')->count(),
                'manuals' => DB::table('ai_documents')->where('source_type', 'manual')->count(),
                'last'    => DB::table('ai_documents')->max('indexed_at'),
            ],
            // Donde la IA se rinde o falla: consultas sin base suficiente y errores técnicos.
            'gaps' => DB::table('ai_suggestions as a')->leftJoin('tickets as t', 't.id', '=', 'a.ticket_id')
                ->whereIn('a.status', ['abstained', 'failed'])->orderByDesc('a.id')->limit(10)
                ->get(['a.id', 'a.status', 'a.error', 't.code as ticket_code', 't.subject', 'a.created_at'])->all(),
            // Las pendientes primero: son las que un responsable tiene que validar o rechazar.
            'corrections' => DB::table('ai_suggestions as a')->leftJoin('tickets as t', 't.id', '=', 'a.ticket_id')
                ->where('a.rating', 'down')->whereNotNull('a.correction')
                ->orderByRaw("a.correction_status = 'pending' DESC")->orderByDesc('a.rated_at')->limit(15)
                ->get(['a.id', 't.code as ticket_code', 'a.correction', 'a.correction_status', 'a.rated_at'])->all(),
            'corrections_pending' => DB::table('ai_suggestions')->where('rating', 'down')->whereNotNull('correction')->where('correction_status', 'pending')->count(),
            'review' => $this->revisionPendiente(),
            'medicion' => \Illuminate\Support\Facades\Cache::remember('ai_medicion', 600, fn () => $this->medicion()),
            'docs_pending' => $this->knowledge->documentacionPendiente(),
            'manuales'   => ManualesCatalogo::resumen(),
        ];
    }

    /** Precio estimado en USD de las filas de una tabla desde una fecha (por modelo). */
    private function gastoTabla(string $tabla, \Carbon\CarbonInterface $desde): float
    {
        $precios = (array) config('ai.prices', []);
        $coste = 0.0;
        foreach (DB::table($tabla)->whereNotNull('input_tokens')->where('created_at', '>=', $desde)
            ->selectRaw('model, SUM(input_tokens) i, SUM(output_tokens) o')->groupBy('model')->get() as $r) {
            [$pi, $po] = $precios[$r->model] ?? $precios['default'] ?? [0, 0];
            $coste += $r->i / 1e6 * $pi + $r->o / 1e6 * $po;
        }
        return $coste;
    }

    /** Tope mensual (USD, mes natural) del uso REAL de los agentes. 0 = sin tope. Se cambia en Ajustes. */
    public function topeMensual(): float
    {
        return max(0.0, (float) Setting::get('ai_budget_month_usd', (string) config('ai.budget_month_usd', 50)));
    }

    /**
     * Gasto estimado del mes natural en curso frente al tope, y el desglose para el panel. Solo cuentan para el tope
     * las sugerencias de los agentes; los chats de prueba (/ia) se enseñan aparte.
     */
    public function presupuesto(): array
    {
        $desde = now()->startOfMonth();
        $tope = $this->topeMensual();
        $gasto = $this->gastoTabla('ai_suggestions', $desde);
        $pct = $tope > 0 ? round($gasto / $tope * 100, 1) : null;
        $precios = (array) config('ai.prices', []);
        $dias = [];
        foreach (DB::table('ai_suggestions')->whereNotNull('input_tokens')->where('created_at', '>=', $desde)
            ->selectRaw('DATE(created_at) d, model, SUM(input_tokens) i, SUM(output_tokens) o, COUNT(*) n')->groupBy('d', 'model')->orderBy('d')->get() as $r) {
            [$pi, $po] = $precios[$r->model] ?? $precios['default'] ?? [0, 0];
            $dias[$r->d] = [
                'dia' => $r->d, 'cost' => round(($dias[$r->d]['cost'] ?? 0) + $r->i / 1e6 * $pi + $r->o / 1e6 * $po, 4),
                'calls' => ($dias[$r->d]['calls'] ?? 0) + (int) $r->n,
            ];
        }
        return [
            'limit' => $tope, 'spent' => round($gasto, 4), 'pct' => $pct,
            'level' => $tope <= 0 ? 'none' : ($gasto >= $tope ? 'over' : ($gasto >= $tope * 0.8 ? 'warn' : 'ok')),
            'from' => $desde->toDateString(), 'resets' => now()->startOfMonth()->addMonth()->toDateString(),
            'days' => array_values($dias),
            'lab' => round($this->gastoTabla('ai_lab_messages', $desde), 4),
        ];
    }

    /** ¿Se ha llegado al tope mensual? Entonces no se llama al proveedor para los agentes. */
    public function topeAlcanzado(): bool
    {
        $tope = $this->topeMensual();
        return $tope > 0 && ($this->gastoTabla('ai_suggestions', now()->startOfMonth()) + $this->gastoTabla('ai_summaries', now()->startOfMonth())) >= $tope;
    }

    /** Tokens y coste ESTIMADO (config ai.prices) de hoy, los últimos 30 días y el total. */
    private function consumo(): array
    {
        $precios = (array) config('ai.prices', []);
        $calc = function ($desde) use ($precios) {
            $in = $out = 0;
            $coste = 0.0;
            $n = 0;
            // Sugerencias de tickets y respuestas de los chats de prueba (/ia).
            foreach (['ai_suggestions', 'ai_lab_messages', 'ai_summaries'] as $tabla) {
                $q = DB::table($tabla)->whereNotNull('input_tokens');
                if ($desde) {
                    $q->where('created_at', '>=', $desde);
                }
                foreach ($q->selectRaw('model, COUNT(*) n, SUM(input_tokens) i, SUM(output_tokens) o')->groupBy('model')->get() as $r) {
                    [$pi, $po] = $precios[$r->model] ?? $precios['default'] ?? [0, 0];
                    $in += (int) $r->i; $out += (int) $r->o; $n += (int) $r->n;
                    $coste += $r->i / 1e6 * $pi + $r->o / 1e6 * $po;
                }
            }
            return ['calls' => $n, 'input' => $in, 'output' => $out, 'cost' => round($coste, 4)];
        };
        return ['today' => $calc(now()->startOfDay()), 'month' => $calc(now()->subDays(30)), 'total' => $calc(null)];
    }

    /**
     * La sugerencia vigente para el último mensaje del cliente; si no hay, se genera.
     * Si el cliente aún no ha escrito nada que contestar, devuelve null.
     */
    public function forTicket(int $ticketId, ?int $userId = null, bool $regenerate = false, array $estilo = []): ?object
    {
        $ult = DB::table('messages')->where('ticket_id', $ticketId)->where('direction', 'in')
            ->where('is_internal_note', 0)->orderByDesc('id')->first(['id', 'body']);
        if (! $ult) {
            return null;
        }

        $estilo = self::estiloValido($estilo);
        if ($estilo) {
            $regenerate = true;   // pedir otro estilo siempre redacta de nuevo
        }
        if (! $regenerate) {
            $ya = DB::table('ai_suggestions')->where('ticket_id', $ticketId)->where('trigger_message_id', $ult->id)
                ->where('status', '!=', 'failed')->orderByDesc('id')->first();
            if ($ya) {
                return $ya;
            }
        }

        $this->estilo = $estilo;
        try {
            return $this->generate($ticketId, (int) $ult->id, $userId);
        } finally {
            $this->estilo = [];
        }
    }

    public const MAX_RESUMEN = 40;

    private const PROMPT_RESUMEN = 'Eres un compañero del equipo de soporte de AEME. Resume la conversación de este ticket para alguien que lo coge ahora y no ha leído nada. Responde en español, sin saludos ni adornos, con EXACTAMENTE estas tres secciones, cada una con 1 a 4 viñetas cortas que empiecen por «- »:
Qué pasó:
Qué se ha probado:
Qué falta:
Reglas: usa solo lo que dicen los mensajes, no inventes nada ni des pasos nuevos al cliente; el cliente es «user» y el equipo «assistant». Si no se ha probado nada o no falta nada, escribe «- Nada por ahora». En «Qué falta» di qué espera cada parte (el cliente o el equipo). Máximo 120 palabras. Nunca repitas contraseñas, tarjetas ni datos personales. Verás [cliente], [email] o [tel]: son datos tapados a propósito; escríbelos tal cual si hace falta, no los adivines.';

    /**
     * Resumen del ticket para quien lo coge (qué pasó, qué se probó, qué falta). Se guarda por último mensaje: mientras no haya
     * mensajes nuevos se reutiliza sin gastar. Nunca lanza: devuelve ['ok' => false, 'error' => …] si no se pudo.
     */
    public function resumen(int $ticketId, ?int $userId, bool $rehacer = false): array
    {
        $base = fn () => DB::table('messages')->where('ticket_id', $ticketId)->where('is_internal_note', 0)->whereNotNull('body');
        $ult = $base()->max('id');
        $n = $base()->count();
        if (! $ult || $n < 2) {
            return ['ok' => true, 'summary' => null];
        }
        $ya = DB::table('ai_summaries')->where('ticket_id', $ticketId)->where('last_message_id', $ult)->orderByDesc('id')->first();
        if ($ya && ! $rehacer) {
            return ['ok' => true, 'summary' => ['body' => $ya->body, 'messages' => $n, 'at' => $ya->created_at]];
        }
        if ($this->topeAlcanzado()) {
            return ['ok' => false, 'error' => 'La IA ha llegado al tope de gasto de este mes. Lee el hilo; volverá el día 1 (o cuando un responsable suba el tope).'];
        }
        // Un mensaje larguísimo (un correo con todo el historial) no debe llenar la petición.
        // Al proveedor no salen el nombre ni el correo del cliente (ni teléfonos, enlaces con parámetros o contraseñas): se tapan aquí.
        $nombre = trim((string) DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')->where('t.id', $ticketId)->value('c.name'));
        $conv = array_map(fn ($m) => ['role' => $m['role'], 'text' => Scrub::tapar(mb_substr($m['text'], 0, 1500), $nombre)], $this->conversacion($ticketId, self::MAX_RESUMEN));
        // La API pide empezar por el cliente y alternar roles: se unen los mensajes seguidos del mismo rol.
        $turnos = [];
        foreach ($conv as $m) {
            if ($turnos && $turnos[count($turnos) - 1]['role'] === $m['role']) {
                $turnos[count($turnos) - 1]['text'] .= "\n" . $m['text'];
            } else {
                $turnos[] = $m;
            }
        }
        if (! $turnos) {
            return ['ok' => true, 'summary' => null];
        }
        if ($turnos[0]['role'] !== 'user') {
            array_unshift($turnos, ['role' => 'user', 'text' => '(el hilo empieza con una respuesta del equipo)']);
        }
        if ($turnos[count($turnos) - 1]['role'] !== 'user') {
            $turnos[] = ['role' => 'user', 'text' => 'Resume el ticket con las tres secciones indicadas.'];
        } else {
            $turnos[count($turnos) - 1]['text'] .= "\n\n(Resume el ticket con las tres secciones indicadas.)";
        }
        try {
            $r = $this->ai->complete(self::PROMPT_RESUMEN, $this->comoTurnos($turnos));
        } catch (\Throwable $e) {
            report($e);
            return ['ok' => false, 'error' => 'La IA no pudo preparar el resumen. Inténtalo de nuevo en un momento.'];
        }
        $texto = trim((string) ($r['text'] ?? ''));
        if ($texto === '' || ! empty($r['truncated'])) {
            return ['ok' => false, 'error' => 'La IA no pudo preparar el resumen. Inténtalo de nuevo en un momento.'];
        }
        // Dentro de casa el agente sí ve el nombre (ya está en el ticket); el correo y el teléfono se quedan tapados.
        $texto = Scrub::sensibles(mb_substr($texto, 0, 2000));
        if ($nombre !== '') {
            $texto = str_replace('[cliente]', $nombre, $texto);
        }
        DB::table('ai_summaries')->insert([
            'ticket_id' => $ticketId, 'last_message_id' => $ult, 'body' => $texto, 'model' => $r['model'] ?? null,
            'input_tokens' => $r['input_tokens'] ?? null, 'output_tokens' => $r['output_tokens'] ?? null,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['ok' => true, 'summary' => ['body' => $texto, 'messages' => $n, 'at' => now()->toDateTimeString()]];
    }

    /** Deja solo los valores permitidos (lo que llega de la pantalla no se fía). */
    public static function estiloValido(array $e): array
    {
        $ok = [];
        foreach (self::ESTILOS as $k => $valores) {
            if (isset($e[$k]) && in_array($e[$k], $valores, true)) {
                $ok[$k] = $e[$k];
            }
        }
        return $ok;
    }

    /** Indicación de redacción para la IA según el estilo elegido; vacía si no hay. */
    private function notaEstilo(): string
    {
        if (! $this->estilo) {
            return '';
        }
        $t = [
            'corta' => 'lo más breve posible: 2 o 3 frases, solo lo imprescindible',
            'detallada' => 'más detallada: explica con pasos numerados y algo más de contexto, sin alargarte con relleno',
            'cercano' => 'tono más cercano y cálido (sin perder el trato de usted)',
            'formal' => 'tono más formal e institucional',
        ];
        $pide = array_map(fn ($v) => $t[$v], array_values($this->estilo));
        return "\n\nESTILO PEDIDO POR EL AGENTE PARA ESTE BORRADOR: " . implode('; ', $pide) . ". Cambia solo la redacción: la decisión (responder, pedir datos o derivar), los datos que pides y las reglas siguen igual.";
    }

    private function generate(int $ticketId, int $messageId, ?int $userId, string $modo = 'suggest', ?int $hasta = null): object
    {
        if ($this->topeAlcanzado()) {
            // Sin llamar al proveedor: el agente lo ve como un fallo con el motivo y puede contestar él.
            $tope = rtrim(rtrim(number_format($this->topeMensual(), 2, '.', ''), '0'), '.');
            $fila = ['sources' => json_encode(['faqs' => [], 'examples' => [], 'docs' => [], 'traza' => [['paso' => 'tope', 'ok' => false, 'texto' => 'Tope mensual de gasto de la IA alcanzado: no se llama al proveedor']]]),
                'status' => 'failed', 'body' => null, 'error' => "Se alcanzó el tope mensual de gasto de la IA ($tope USD). Contesta tú; se reactiva el día 1."];
        } else {
            $fila = $this->redactar($ticketId, $this->ai, false, $hasta);
        }
        $fila += [
            'ticket_id' => $ticketId, 'trigger_message_id' => $messageId, 'mode' => $modo,
            'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
        ];
        $id = DB::table('ai_suggestions')->insertGetId($fila);
        return DB::table('ai_suggestions')->where('id', $id)->first();
    }

    /**
     * Pide el borrador a un proveedor SIN guardar nada (la fila la compone quien llama).
     * Lo usa también la comparación entre proveedores. Con $anonimizar se tapan correos,
     * teléfonos y el nombre del cliente antes de que el texto salga hacia fuera.
     * Nunca lanza: un fallo vuelve como status «failed».
     *
     * @return array{status:string, body:?string, sources:string, model?:?string, input_tokens?:?int, output_tokens?:?int, error?:string}
     */
    public function redactar(int $ticketId, AiProvider $ai, bool $anonimizar = false, ?int $hasta = null): array
    {
        $this->anonimizar = $anonimizar;
        $this->hasta = $hasta;
        try {
            $t = DB::table('tickets as t')->leftJoin('contacts as c', 'c.id', '=', 't.contact_id')
                ->leftJoin('ticket_categories as cat', 'cat.id', '=', 't.category_id')
                ->where('t.id', $ticketId)
                ->first(['t.id', 't.subject', 't.category_id', 't.channel', 't.contact_id', 'c.name as contact_name', 'c.email as contact_email', 'cat.name as category_name']);
            $this->nombreCliente = $anonimizar ? trim((string) ($t->contact_name ?? '')) : '';
            if ($anonimizar) {
                $t->subject = $this->plano((string) $t->subject);
                $t->contact_name = null;
            }

            return $this->componer($t, $this->conversacion($ticketId), $ticketId, $ai, $anonimizar);
        } finally {
            $this->anonimizar = false;
            $this->hasta = null;
            $this->nombreCliente = '';
        }
    }

    /**
     * Compone la respuesta a partir de un «ticket» ya cargado y su conversación ([{role, text}]). Lo comparten los
     * tickets reales y los chats de prueba ($ticketId null). Nunca lanza: un fallo vuelve como status «failed».
     */
    private function componer(object $t, array $conversacion, ?int $ticketId, AiProvider $ai, bool $anonimizar): array
    {
        $this->traza = [];
        $this->derivarA = null;
        // Solo audios de WhatsApp (todavía no se transcriben): texto fijo y cortés, sin llamar al proveedor (no gasta nada).
        if (self::soloAudios($conversacion)) {
            $this->paso('filtro', null, 'El cliente solo ha enviado audios: se prepara el texto fijo de «no podemos escuchar audios» (sin llamar a la IA)');
            $this->paso('salida', true, 'Respuesta preparada: texto fijo (no se gastó nada)');
            return ['sources' => json_encode(['faqs' => [], 'examples' => [], 'docs' => [], 'traza' => $this->traza]), 'status' => 'ready', 'body' => self::TEXTO_AUDIO, 'model' => null, 'input_tokens' => 0, 'output_tokens' => 0];
        }
        $this->avisar('Leyendo la conversación y buscando en la memoria…');
        $pregunta = $this->ultimaDelCliente($conversacion);
        $faqs = $this->faqsParaTexto(($t->subject ?? '') . ' ' . $pregunta);
        $ejemplos = $this->ejemplos($t->category_id ? (int) $t->category_id : null);
        $casos = $this->knowledge->buscar(($t->subject ?? '') . ' ' . $pregunta, $ticketId ?? $this->excluirTicket, 3, 4, 2, (string) ($t->subject ?? ''));

        $t->codigos = $ticketId ? $this->leerCodigosDeFotos($ticketId) : [[], 0];
        $textoCliente = ($t->subject ?? '') . "\n" . implode("\n", array_column(array_filter($conversacion, fn ($m) => $m['role'] === 'user'), 'text'));
        $t->hotel = ($anonimizar && ! $this->evalApi) ? '' : $this->contextoHotel($t->contact_email ?? null, array_values(array_unique(array_merge($t->codigos[0] ?? [], $this->codigosEnTexto($pregunta)))), $textoCliente, $t->contact_name ?? null, $anonimizar ? null : ($t->contact_id ?? null));

        $hotelNombrado = $this->hotelLocalizado || (bool) $this->nombresDeHotel($textoCliente);
        // Evaluaciones: los casos van anonimizados y sin correo, así que la norma de verificar primero NO se aplica (se mide la decisión de fondo; la norma la cubren los tests).
        $dec = self::decidir($pregunta . ' ' . (string) ($t->subject ?? '') . ' ' . (string) ($t->contact_email ?? ''), $conversacion, $this->estadoCorreo, $hotelNombrado, $this->hotelNoEncontrado || $anonimizar, self::ajenas(), $this->hotelSinApi);
        // Historial (tickets anteriores del cliente o de su hotel): solo en tickets reales y con el correo ya VERIFICADO.
        $t->historial = '';
        if (! $anonimizar && $ticketId && ! empty($t->contact_id) && $this->estadoCorreo === 'verificado') {
            try {
                $t->historial = HistorialCliente::para((int) $t->contact_id, $ticketId);
            } catch (\Throwable $e) {
                $t->historial = '';
            }
            if ($t->historial !== '') {
                $this->paso('historial', null, 'Historial del cliente añadido como contexto (tickets anteriores suyos o de su hotel)');
            }
        }
        $t->decision = $dec ? "\nDECISIÓN YA TOMADA POR EL SISTEMA (obligatoria; no la discutas ni la cambies): " . $dec['instruccion'] . "\n" : '';
        if ($dec) {
            $this->paso('decision', null, 'Decisión fijada en código: ' . $dec['resumen']);
        }

        $this->paso('memoria', null, 'Memoria consultada: ' . count($faqs) . ' preguntas frecuentes · ' . count($ejemplos) . ' respuestas de ejemplo · ' . count($casos) . ' casos/manuales parecidos'
            . ($casos ? ' (' . implode('; ', array_map(fn ($d) => mb_substr((string) ($d->title ?? ''), 0, 60), array_slice($casos, 0, 4))) . ')' : ''),
            ['faqs' => count($faqs), 'ejemplos' => count($ejemplos), 'casos' => array_map(fn ($d) => ['tipo' => (string) ($d->source_type ?? ''), 'titulo' => mb_substr((string) ($d->title ?? ''), 0, 90)], array_slice($casos, 0, 6))]);
        $extraDocs = [];   // lo que la IA buscó ella misma durante la redacción (también cuenta como fuente)
        $fuentes = function () use ($faqs, $ejemplos, $casos, &$extraDocs) {
            return json_encode(['faqs' => array_column($faqs, 'id'), 'examples' => array_column($ejemplos, 'id'), 'docs' => array_values(array_unique(array_merge(array_map(fn ($d) => (int) $d->id, $casos), $extraDocs))), 'traza' => $this->traza, 'derivar' => $this->derivarA, 'estilo' => $this->estilo ?: null]);
        };
        $fila = ['sources' => $fuentes()];
        $this->avisar('Redactando la respuesta…');
        try {
            $sistema = $this->instrucciones($t, $faqs, $ejemplos, $casos) . $this->notaEstilo();
            if ($ai instanceof AiProviderConHerramientas && config('ai.memoria_herramienta', true)) {
                // La IA consulta la memoria por su cuenta si lo que se le dio no basta (como una herramienta interna; el agente no ve ningún botón).
                $r = $ai->completeConHerramientas($sistema, $this->comoTurnos($conversacion), [self::HERRAMIENTA_MEMORIA],
                    fn (string $nombre, array $entrada) => $this->buscarParaLaIa($nombre, $entrada, $extraDocs),
                    (int) config('ai.memoria_max_busquedas', 2));
            } else {
                $r = $ai->complete($sistema, $this->comoTurnos($conversacion));
            }
            if (! empty($r['truncated'])) {
                // Un texto cortado a medias no se puede enseñar como si estuviera completo.
                $fila += ['model' => $r['model'], 'input_tokens' => $r['input_tokens'], 'output_tokens' => $r['output_tokens']];   // se pagó aunque no sirva
                throw new AiException('La respuesta de la IA salió incompleta');
            }
            [$texto, $this->derivarA] = self::sacarDerivacion(trim($r['text']));
            $texto = self::aplicarFraseDerivacion($texto, $this->derivarA);
            $sabe = $texto !== '' && ! str_contains($texto, self::NO_SABE);
            $dest = $this->derivarA ? ' · llevar a ' . $this->derivarA['categoria'] : '';
            $this->paso('salida', $sabe ? true : null, $sabe ? 'Respuesta preparada · ' . ($this->derivarA ? 'deriva a una persona del equipo' : self::tipoDeRespuesta($texto)) . $dest . ' (estimado)' : 'La IA no se atreve: no hay borrador, lo debe llevar una persona' . $dest);
            $fila['sources'] = $fuentes();
            return $fila + [
                'status' => $sabe ? 'ready' : 'abstained', 'body' => $sabe ? $texto : null,
                'model' => $r['model'], 'input_tokens' => $r['input_tokens'], 'output_tokens' => $r['output_tokens'],
            ];
        } catch (Throwable $e) {
            report($e);
            $this->paso('salida', false, 'La IA no pudo preparar la respuesta');
            $fila['sources'] = $fuentes();
            return $fila + ['status' => 'failed', 'body' => null, 'error' => mb_substr($e instanceof AiException ? $e->getMessage() : 'Error inesperado', 0, 250)];
        }
    }

    /** La herramienta que ve la IA. Las pautas de uso van aquí (el prompt está al límite de su tamaño). */
    public const HERRAMIENTA_MEMORIA = [
        'name' => 'buscar_memoria',
        'description' => 'Busca en los manuales de la aplicación, los casos ya resueltos y las correcciones del equipo. Úsala SOLO si lo que ya se te ha dado no cubre lo que pregunta el cliente, o para comprobar el nombre exacto de una pantalla o botón antes de nombrarlo. Pocas palabras clave en español (no frases enteras). Si no encuentras nada útil, no inventes: sigue las reglas (pedir datos o pasarlo a una persona).',
        'input_schema' => ['type' => 'object', 'properties' => ['consulta' => ['type' => 'string', 'description' => 'Palabras clave de lo que buscas, p. ej. «cambiar idioma etiqueta»']], 'required' => ['consulta']],
    ];

    /** Ejecuta la búsqueda que pide la IA y apunta en la traza qué buscó. Los documentos ya vienen sin datos personales. */
    private function buscarParaLaIa(string $nombre, array $entrada, array &$extraDocs): string
    {
        $q = is_string($entrada['consulta'] ?? null) ? trim(mb_substr($entrada['consulta'], 0, 200)) : '';
        if ($nombre !== 'buscar_memoria' || mb_strlen($q) < 3) {
            return 'Escribe al menos 3 letras de lo que buscas.';
        }
        $t0 = microtime(true);
        $docs = $this->knowledge->consultar($q);
        ApiMonitor::registrar('memoria', 'la IA busca (herramienta)', microtime(true) - $t0, true);
        $this->paso('busqueda', null, 'La IA buscó en la memoria: «' . $q . '» → ' . count($docs) . ' resultado' . (count($docs) === 1 ? '' : 's'),
            ['consulta' => $q, 'resultados' => array_map(fn ($d) => ['tipo' => (string) $d->source_type, 'titulo' => mb_substr((string) $d->title, 0, 90)], array_slice($docs, 0, 6))]);
        if (! $docs) {
            return 'No hay nada parecido en la memoria.';
        }
        $out = [];
        foreach (array_slice($docs, 0, 5) as $d) {
            $extraDocs[] = (int) $d->id;
            $txt = (string) $d->content;
            $out[] = '[' . $d->source_type . '] ' . $d->title . "
" . (mb_strlen($txt) > 700 ? mb_substr($txt, 0, 700) . '…' : $txt);
        }
        return implode("
---
", $out);
    }

    public const AJENAS_POR_DEFECTO = ['Don Pancho'];

    /** Hoteles/aplicaciones que no gestionamos nosotros (Ajustes › Asistente IA). Sin configurar: la lista de siempre. */
    public static function ajenas(): array
    {
        $v = Setting::get('ai_apps_ajenas', null);
        return $v === null ? self::AJENAS_POR_DEFECTO : self::limpiarAjenas((string) $v);
    }

    /** Una por línea o separadas por comas; sin vacías ni repetidas, de 3 a 60 caracteres, máximo 50. */
    public static function limpiarAjenas(string $texto): array
    {
        $l = array_map(fn ($x) => trim(strip_tags($x)), preg_split('/[\r\n,;]+/u', $texto) ?: []);
        $l = array_filter($l, fn ($x) => mb_strlen($x) >= 3 && mb_strlen($x) <= 60);
        $vistos = [];
        foreach ($l as $x) {
            $vistos[mb_strtolower($x)] ??= $x;
        }
        return array_slice(array_values($vistos), 0, 50);
    }

    /**
     * Decide EN CÓDIGO los casos claros que el modelo resolvía a veces de una forma y a veces de otra (derivar o pedir datos).
     * Función pura: solo mira el último mensaje del cliente, la conversación y lo que se supo del correo y el hotel.
     * Devuelve null si el caso no es claro: entonces decide el modelo con las reglas del prompt.
     *
     * @param  array<int, array{role:string, text:string}>  $conversacion
     * @return array{decision:string, instruccion:string, resumen:string}|null
     */
    public static function decidir(string $texto, array $conversacion, ?string $estadoCorreo, bool $hotelNombrado, bool $noEsHotel = false, ?array $ajenas = null, bool $appSinApi = false): ?array
    {
        $t = mb_strtolower((string) preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($texto, \Normalizer::FORM_D) ?: $texto));
        $derivar = fn (string $cat, string $motivo) => ['decision' => 'derivar', 'resumen' => "derivar ($motivo)",
            'instruccion' => "DERIVA. Responde con la frase corta «" . self::fraseDerivacion(self::DEPARTAMENTOS[$cat]['nombre'] ?? 'Soporte') . "» sin preguntar nada ni dar pasos, y añade al final «[[DERIVAR:$cat|$motivo]]»."];
        $yaRespondio = (bool) array_filter($conversacion, fn ($m) => ($m['role'] ?? '') === 'assistant');

        // Hoteles o aplicaciones que no gestionamos nosotros (lista editable en Ajustes › Asistente IA): cualquier incidencia la lleva una persona.
        foreach ($ajenas ?? self::AJENAS_POR_DEFECTO as $nombre) {
            $n = mb_strtolower((string) preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($nombre, \Normalizer::FORM_D) ?: $nombre));
            $partes = array_filter(preg_split('/\s+/u', trim($n)) ?: [], fn ($p) => $p !== '');
            if (mb_strlen(trim($n)) >= 3 && preg_match('/(?<![\p{L}\p{N}])' . implode(' ?', array_map(fn ($p) => preg_quote($p, '/'), $partes)) . '(?![\p{L}\p{N}])/u', $t)) {
                return $derivar('soporte', $nombre . ' (su app no la gestionamos nosotros)');
            }
        }
        // Hotel de una app que no está en la API de soporte (Poseidón, Hoteles03, Fira Barcelona, Lavaka): no se puede verificar, lo lleva una persona.
        if ($appSinApi) {
            return $derivar('soporte', 'hotel cuya app aún no está en la API de soporte');
        }
        // Temas que SIEMPRE lleva una persona (la farmacia es otra regla: la lleva Expofarm).
        $farmacia = str_contains($t, 'farmacia');
        // REGLA DE LA DIRECCIÓN (20-nov): en un hotel, SIEMPRE se verifica antes de contestar, pedir otros datos o derivar. Si aún no hay
        // correo verificado (ni se intentó comprobar), lo primero es pedir hotel y correo. Salvo: pedidos de material y presupuestos (no
        // dependen del hotel), farmacias, tiendas y supermercados (otras áreas, otra verificación) y quien nombra un sitio que el listado de
        // hoteles no conoce (quizá no sea un hotel; ahí manda VERIFICACIÓN DEL CLIENTE: pedir el nombre exacto y, si sigue sin constar, derivar).
        $material = preg_match('/presupuesto|(comprar|compra|pedir|pedido|encarg[a-z]+|reponer|necesit[a-z]+)\b.{0,40}(pilas|soportes?|recambios?|pinzas|etiquetas|tapas)/s', $t);
        // Tienda o comercio (no un hotel): balanza, báscula, caja, carnicería, frutería… De momento los lleva una persona del equipo: ni se
        // les pide «hotel» ni se les explica nada. Solo si aún no hay un correo verificado (si ya es un usuario de la app, manda lo demás).
        // Vocabulario y nombres sacados de los clientes reales que no son hoteles (carnicerías, pescaderías, supermercados, garden, bricolaje, vinos, mascotas, pádel…).
        $comercio = preg_match('/\b(tiendas?|supermercados?|balanzas?|basculas?|caja registradora|tpv|carnicerias?|charcuterias?|fruterias?|pescaderias?|panaderias?|pastelerias?|polleria|ultramarinos|autoservicio|ferreteria|bricolaje|garden|jardineria|bodega|licoreria|peluqueria|optica|libreria|mascotas|cash ?& ?carry|mercadillo)\b'
            . '|\b(alondra|bernal|calvet|areapadel|carviresa|calviresa|cashsur|dispival|goshop|la ?joyosa|lomillos|nova ?garden|penalver|recambios gil|sancrispin|soyoptima|superplus|openplus|ubemar|dialprix|euronics|brico moreno|mischuches|futbol ?factory|vilato|lovepets|divelsa|dps ?market)\b/', $t);
        $otraArea = $farmacia || $comercio;
        $acuse = mb_strlen($t) < 200 && preg_match('/gracias|ya funciona|solucionado|resuelto/', $t) && ! str_contains($t, '?');
        if (! $acuse && ! $noEsHotel && ! in_array($estadoCorreo, ['verificado', 'incompleto', 'no_encontrado', 'desactivado'], true) && ! $material && ! $otraArea) {
            return ['decision' => 'pedir_datos', 'resumen' => $hotelNombrado ? 'verificar primero: pedir el correo' : 'verificar primero: pedir hotel y correo',
                'instruccion' => ($hotelNombrado
                    ? 'ANTES de contestar, pedir datos o derivar hay que VERIFICAR al cliente. El hotel ya consta: PIDE SOLO el correo con el que accede a la aplicación.'
                    : 'ANTES de contestar, pedir datos o derivar hay que VERIFICAR al cliente. PIDE SOLO el nombre de su establecimiento y el correo con el que accede a nuestra aplicación (como máximo esas dos preguntas). No des por hecho que es un hotel (puede ser tienda o farmacia): no digas «hotel» ni «aplicación de hoteles».')
                    . ' No des pasos, diagnóstico ni derives todavía; di que en cuanto lo confirme se lo atiende.'];
        }
        if ($comercio && ! $farmacia && ! $material && ! $acuse && $estadoCorreo !== 'verificado') {
            return $derivar('soporte', 'tienda o comercio, no un hotel');
        }
        // Alérgenos: lo sensible (qué lleva un plato, datos mal o ausentes) lo lleva una persona; qué alérgenos se muestran lo configura el hotel.
        $configuraAlergenos = preg_match('/alergen/', $t)
            && preg_match('/\b(activar|activa|activado|desactivar|apagar|encender|ocultar|aparec\w*|salen|lista|cuantos)\b|configuracion/', $t)
            && ! preg_match('/plato|receta|ingrediente|contiene|\blleva|traza|gluten|celiac|intoleran|reaccion|incorrect|erron|\bmal\b|falta/', $t);
        if (preg_match('/alergen/', $t) && ! $configuraAlergenos) {
            return $derivar('soporte', 'alérgenos');
        }
        if (preg_match('/\btotems?\b/', $t)) {
            return $derivar('soporte', 'tótem');
        }
        // «Garantía» deriva siempre, salvo que solo la mencione de pasada: pide precio o presupuesto para COMPRAR material (otra etiqueta,
        // pinzas…) y no cuenta ningún fallo. Eso es un presupuesto de material, no una garantía (ej.: «estoy revisando su protocolo de garantía… me
        // conviene comprar otra etiqueta; envíenme presupuesto»).
        $soloCompra = preg_match('/\b(precio|presupuesto)/', $t) && preg_match('/\b(comprar\w*|compra|pinzas|portaetiquetas)\b/', $t)
            && ! preg_match('/\b(no funcion\w*|fallo\w*|fallan|averi\w*|rotas?|rotos?|defectuos\w*|sustitu\w*|reemplaz\w*|no cambia\w*|se apag\w*|en blanco|bloquead\w*|estropead\w*|no enciende|humedad|no carga\w*)\b/', $t);
        // Según el CONTEXTO (decisión de Juan, 24-nov): la palabra «garantía» deriva directo en código salvo cuando el cliente cuenta, con
        // naturalidad, una etiqueta (o unas pocas) que no responde y habla de pilas, reinicio o forzar sin daño visible: ahí el modelo lee
        // la conversación y aplica el protocolo (reinicio → pilas de otra etiqueta → tres fotos → Garantías). Siguen derivando en código el
        // seguimiento de una garantía ya abierta («¿cómo está esto?», «no me han dicho nada», ya enviadas), un lote grande y el daño evidente.
        $seguimiento = preg_match('/como (esta|va|andan?)\b|no (me|nos) (han|ha) (dicho|contestado|respondido|llamado)|sin (noticias|respuesta)|(estado|seguimiento|situacion) de (la|las|mi|nuestra|esta)|cuando (nos|me|las|los|va)\b|ya (os |les |se )?(envi|mand|entreg)|enviad[oa]s?\b|recibisteis|han recibido|hace (unos |varios )?(dias|semanas|un mes|meses)|numero de (rma|garantia)|\brma\b|retras|reclam/', $t);
        $sintomaDelProtocolo = preg_match('/\b(no (funcion\w*|cambia\w*|responde\w*|actualiza\w*|enciende)|en blanco|bloquead\w*|se apag\w*|se queda\w* (en )?(negro|blanco)|pilas?|reinici\w*|forz\w*)\b/', $t)
            && ! preg_match('/\b(rotas?|rotos?|roturas?|humedad|golpe\w*|averi\w*|defectuos\w*|led (rojo|fijo|encendido)|luces?)\b/', $t);
        $loteGrande = preg_match('/\b(\d{2,} (etiquetas|carteles|pantallas|unidades|equipos|aparatos)|todas|todos|lote|decenas|cientos)\b/', $t);
        if (preg_match('/\bgarantia/', $t) && ! $farmacia && ! $soloCompra && ! ($sintomaDelProtocolo && ! $seguimiento && ! $loteGrande)) {
            return $derivar('garantias', 'garantía');
        }
        // Problema de uso, primera vez que escribe y no se sabe quién es: se piden hotel y correo (y nada más).
        $uso = preg_match('/(no|tampoco) (se )?(cambian|cambia|actualizan|actualiza|refrescan)|no (me )?(deja|dejan) (entrar|acceder)|no (puedo|podemos) (entrar|acceder)|unauthorized|error al (emparejar|vincular)/', $t);
        if ($uso && ! $yaRespondio && ! $hotelNombrado && $estadoCorreo !== 'verificado') {
            return ['decision' => 'pedir_datos', 'resumen' => 'pedir hotel y correo',
                'instruccion' => 'PIDE SOLO el nombre de su establecimiento y el correo con el que accede a nuestra aplicación (como máximo esas dos preguntas). No des por hecho que es un hotel (puede ser tienda o farmacia): no digas «hotel» ni «aplicación de hoteles», sin dar pasos, diagnóstico ni derivar.'];
        }
        // Lo que parece garantía pero lo hace el hotel: sustituir una etiqueta rota por otra que ya tienen (dan su código) y darla de alta
        // en la posición, o poner pilas nuevas y forzar. Sin pedir envío ni garantía. No se deriva: primero se identifica y luego se explica.
        // Piden etiquetas de reposición pero alguna solo está bloqueada o no cambia (aunque la fuercen) y no han probado el reinicio: primero
        // el reinicio de esa (la dañada, por humedad o pantalla rota, no se menciona). No es solo garantía.
        $pideReposicion = preg_match('/reposicion|envi\w*.{0,40}etiquetas?|etiquetas?.{0,20}nuev/s', $t);
        $bloqueada = preg_match('/bloquead|(no|tampoco) (se )?(cambia|cambian)/', $t);
        $yaProbo = preg_match('/pilas (nuevas|cambiad)|cambiad\w* (las )?pilas|cambiamos las pilas|quitad\w* (las )?pilas|reinicio|reiniciad/', $t);
        if ($pideReposicion && $bloqueada && ! $yaProbo && ! $yaRespondio && ! preg_match('/garantia/', $t)) {
            if (! $hotelNombrado && $estadoCorreo !== 'verificado') {
                return ['decision' => 'pedir_datos', 'resumen' => 'pedir hotel y correo (una está solo bloqueada)',
                    'instruccion' => 'PIDE SOLO el nombre de su establecimiento y el correo con el que accede a nuestra aplicación (como máximo esas dos preguntas). No des por hecho que es un hotel (puede ser tienda o farmacia): no digas «hotel» ni «aplicación de hoteles», sin dar pasos ni derivar.'];
            }
            return ['decision' => 'responder', 'resumen' => 'reinicio de la etiqueta bloqueada antes de derivar',
                'instruccion' => 'Alguna de las etiquetas solo está BLOQUEADA o no cambia (sin daño visible): NO derives todavía. Explica el protocolo de reinicio de la etiqueta de la guía (quitar las pilas 15 minutos, con las antenas en línea) para esa. NO menciones la etiqueta dañada (humedad, pantalla rota) ni prometas sustituir o enviar nada. Si el correo no está verificado, pídelo primero (VERIFICACIÓN DEL CLIENTE).'];
        }
        $sinEnvio = ! preg_match('/envi|garantia|repuesto|pedido|presupuesto|nuevas etiquetas|comprar/', $t);
        $reasignar = $sinEnvio && preg_match('/(sustitu|reempla|cambiar).{0,120}(rot[ao]s?|pantalla)|(rot[ao]s?|pantalla).{0,120}(sustitu|reempla)/s', $t) && preg_match('/\balta\b/', $t);
        $pilasForzar = $sinEnvio && preg_match('/pilas?.{0,60}(forz|actualiz)|(forz|actualiz).{0,60}pilas?/s', $t);
        if ($reasignar || $pilasForzar) {
            if (! $yaRespondio && ! $hotelNombrado && $estadoCorreo !== 'verificado') {
                return ['decision' => 'pedir_datos', 'resumen' => 'pedir hotel y correo (no es garantía)',
                    'instruccion' => 'PIDE SOLO el nombre de su establecimiento y el correo con el que accede a nuestra aplicación (como máximo esas dos preguntas). No des por hecho que es un hotel (puede ser tienda o farmacia): no digas «hotel» ni «aplicación de hoteles», sin dar pasos, diagnóstico ni derivar. NO lo trates como garantía.'];
            }
            return ['decision' => 'responder', 'resumen' => 'no es garantía: lo hace el hotel',
                'instruccion' => $reasignar
                    ? 'NO es una garantía ni se deriva: sustituir una etiqueta por otra que el hotel ya tiene y darla de alta en la posición lo hace el propio hotel. EXPLICA los pasos de la guía (Posiciones), sin confirmar que ya está hecho.'
                    : 'NO es una garantía ni se deriva: poner pilas nuevas y forzar la etiqueta lo hace el propio hotel. EXPLICA con la guía el reinicio de la etiqueta y cómo forzar el menú; solo se deriva si ya lo hizo y sigue igual.'];
        }
        return null;
    }
    /**
     * Chat de pruebas (página /ia, solo local): contesta al último mensaje de $conv como si fuera un ticket nuevo.
     * $chat: title, contact_name, contact_email. Devuelve lo mismo que redactar().
     */
    public function responderChat(object $chat, array $conv, AiProvider $ai, bool $forzar = false): array
    {
        // Si el cliente solo acusa recibo («ok», «gracias», «perfecto», un pulgar…) después de que ya se le contestó, no se vuelve a
        // escribir: hacerlo en cada «ok» es molesto y conversación sin fin. No se llama al proveedor (no gasta nada).
        if (! $forzar && self::soloAcusaRecibo($conv)) {
            return ['status' => 'silent', 'body' => null];
        }
        $t = (object) [
            'id' => null, 'subject' => (string) $chat->title, 'category_id' => null, 'channel' => 'chat', 'category_name' => null,
            'contact_name' => $chat->contact_name ?: null, 'contact_email' => $chat->contact_email ?: null,
            'yaRespondio' => (bool) array_filter($conv, fn ($m) => $m['role'] === 'assistant'),
        ];
        return $this->componer($t, $conv, null, $ai, false);
    }

    /** ¿Lo último que escribió el cliente (tras la última respuesta) es solo un acuse de recibo? Solo cuenta si ya se le había contestado. */
    /**
     * La IA, al derivar, añade en una línea aparte «[[DERIVAR:categoria|motivo]]». Es una nota interna para el agente (a qué
     * categoría de la app debe llevarlo): se quita del texto y se guarda aparte. Devuelve [texto limpio, ['categoria','motivo']|null].
     */
    public static function sacarDerivacion(string $texto): array
    {
        $dest = null;
        $limpio = preg_replace_callback('/\s*\[\[DERIVAR:\s*([a-záéíóú]+)\s*(?:\|\s*([^\]]*?))?\s*\]\]\s*/iu', function ($m) use (&$dest) {
            $k = strtr(mb_strtolower($m[1]), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
            $k = str_starts_with($k, 'pedido') ? 'postventa' : (str_starts_with($k, 'garant') ? 'garantias' : (str_starts_with($k, 'proyect') ? 'proyectos' : $k));
            if (isset(self::DEPARTAMENTOS[$k]) && $dest === null) {
                $d = self::DEPARTAMENTOS[$k];
                $dest = ['categoria' => $d['categoria'], 'departamento' => $d['nombre'], 'clave' => $k, 'categoria_key' => $d['categoria_key'], 'motivo' => mb_substr(trim($m[2] ?? ''), 0, 160)];
            }
            return "\n";
        }, $texto);
        return [trim((string) $limpio), $dest];
    }

    /** Clasificación aproximada de lo que hace el borrador, solo para el panel del agente. */
    private static function tipoDeRespuesta(string $t): string
    {
        if (preg_match('/compañer[oa]s? (del equipo )?(revisar|le atender|comprobar|se pondr)|equipo (de [\wáéíóú\/ ]+ )?(revisar|le atender)|derivad[ao] al departamento/iu', $t)) {
            return 'deriva a una persona del equipo';
        }
        if (preg_match('/\?/u', mb_substr($t, -400))) {
            return 'pide datos o confirma con el cliente';
        }
        return 'responde o explica';
    }

    public static function soloAcusaRecibo(array $conv): bool
    {
        $ultimos = [];
        foreach (array_reverse($conv) as $m) {
            if ($m['role'] !== 'user') {
                break;
            }
            array_unshift($ultimos, (string) $m['text']);
        }
        if (! $ultimos || count($ultimos) === count($conv) || ! array_filter($conv, fn ($m) => $m['role'] === 'assistant')) {
            return false;
        }
        $acuses = ['ok', 'okey', 'okay', 'vale', 'gracias', 'muchas', 'mil', 'perfecto', 'genial', 'entendido', 'listo', 'hecho', 'bien',
            'de', 'acuerdo', 'estupendo', 'dale', 'buenisimo', 'recibido', 'enterado', 'ya', 'te', 'lo', 'se', 'agradezco', 'muy', 'amable', 'adios', 'hasta', 'luego', 'saludos'];
        foreach ($ultimos as $t) {
            $t = trim($t);
            $letras = mb_strtolower(preg_replace('/[^\p{L}\p{N} ]+/u', ' ', strtr(mb_strtolower($t), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u'])));
            $palabras = array_values(array_filter(preg_split('/\s+/', trim($letras))));
            if ($t === '' || mb_strlen($t) > 40) {
                return false;
            }
            if (! $palabras) {   // solo emojis o signos (un pulgar)
                continue;
            }
            if (count($palabras) === 1 && mb_strlen($palabras[0]) <= 1) {   // una letra suelta («o»)
                continue;
            }
            foreach ($palabras as $p) {
                if (! in_array($p, $acuses, true)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Evaluación (comando ai:evaluar): contesta a un caso ya anonimizado como si fuera un ticket nuevo, sin guardar nada
     * y sin consultar datos de ningún hotel. $excluirTicket es el ticket local del propio caso, para que no se «copie».
     */
    public function evaluarCaso(string $asunto, string $pregunta, ?int $excluirTicket, AiProvider $ai, bool $conApi = false, ?array $conversacion = null, ?string $canal = null): array
    {
        $this->excluirTicket = $excluirTicket;
        $this->evalApi = $conApi;   // casos [API]: la IA también ve lo que AdminHoteles diga del hotel que nombra el mensaje (solo lectura)
        try {
            $t = (object) ['id' => null, 'subject' => $asunto, 'category_id' => null, 'channel' => $canal ?: 'chat', 'category_name' => null,
                'contact_name' => null, 'contact_email' => null, 'yaRespondio' => (bool) array_filter($conversacion ?? [], fn ($m) => $m['role'] === 'assistant')];
            return $this->componer($t, $conversacion ?: [['role' => 'user', 'text' => $pregunta]], null, $ai, true);
        } finally {
            $this->excluirTicket = null;
            $this->evalApi = false;
        }
    }

    /**
     * Mapa de la aplicación de hoteles: el índice de la documentación (apartados de uso diario), SIEMPRE presente en el prompt.
     * La búsqueda de guías puede no traer la del apartado que toca; con el mapa la IA sabe qué hace el propio hotel (servicios,
     * idiomas, posiciones, usuarios…) y no lo toma por trabajo del equipo.
     */
    private function mapaApp(): string
    {
        $ruta = resource_path('ai-docs/hoteles/00-indice.md');
        if (! is_file($ruta)) {
            return '';
        }
        $t = (string) file_get_contents($ruta);
        $t = (string) preg_replace('/^.*?(?=^## General)/ms', '', $t);               // quita título y párrafo de presentación
        $t = (string) preg_replace('/^## (Desperdicio|Operativa|Eventos|Wellness|Restaurante).*\z/ms', '', $t);   // módulos de nicho
        $t = (string) preg_replace('/\[([^\]]+)\]\([^)]+\)/u', '$1', $t);            // [Servicios](15-servicios.md) → Servicios
        return "\nMAPA DE LA APLICACIÓN DE HOTELES (todo esto lo hace el PROPIO HOTEL desde la aplicación, con su usuario y su rol; NO es trabajo del equipo de AEME. Si lo que pide el cliente está aquí, explícalo con la guía (los platos sueltos, las ruedas de menú, los idiomas y la plantilla de etiqueta también los gestiona el hotel; el equipo solo hace el logo, importar platos desde Excel y dar de alta etiquetas nuevas en Conectividad). La guía detallada de cada apartado, si hace falta, sale más abajo)\n" . trim($t) . "\n";
    }

    /**
     * Valora una sugerencia. «down» exige la corrección: sin ella no hay nada de lo que
     * aprender, y es justo lo que se guarda para alimentar a la IA.
     */
    /** Motivos de un 👎 rápido (un clic). */
    public const MOTIVOS = ['tema' => 'Se equivocó de tema', 'dato' => 'Faltó un dato o preguntó de más', 'derivar' => 'Debía pasarlo a una persona', 'responder' => 'Podía responder y no lo hizo', 'inventa' => 'Inventó o dio algo incorrecto', 'tono' => 'Tono o redacción'];

    public function rate(int $id, string $rating, ?string $correction, int $userId, ?string $reason = null): object
    {
        if (! in_array($rating, ['up', 'down'], true)) {
            throw new InvalidArgumentException('Valoración no válida');
        }
        $correction = trim((string) $correction);
        $reason = $rating === 'down' && $reason !== null && isset(self::MOTIVOS[$reason]) ? $reason : null;
        // Un 👎 vale con un motivo de un clic (queda apuntado) o con una corrección escrita; solo la corrección alimenta a la IA.
        if ($rating === 'down' && mb_strlen($correction) < 5 && $reason === null) {
            throw new InvalidArgumentException('Elige un motivo o escribe qué debía responder (mínimo 5 letras)');
        }
        $conCorreccion = $rating === 'down' && mb_strlen($correction) >= 5;

        // Decisión de Juan (18-nov): nadie va a revisar lo que dijo cada agente, así que lo que corrige un agente pasa DIRECTO a la
        // memoria (queda como validado por quien lo escribió; el historial guarda quién, cuándo y qué). Un responsable (support.config)
        // puede seguir retirando una corrección mala desde Ajustes (admin_review → rejected), pero no es un paso obligatorio.
        $propia = $conCorreccion;
        DB::table('ai_suggestions')->where('id', $id)->update([
            'rating' => $rating, 'correction' => $conCorreccion ? mb_substr($correction, 0, 4000) : null, 'reason' => $reason,
            'rated_by' => $userId, 'rated_at' => now(), 'updated_at' => now(),
            'correction_status' => $propia ? 'validated' : 'pending',
            'correction_reviewed_by' => $propia ? $userId : null,
            'correction_reviewed_at' => $propia ? now() : null,
        ]);
        // Si la corrección está activa pasa a ser conocimiento buscable (y deja de serlo si cambia a 👍).
        $this->knowledge->indexarCorreccion($id);
        $fila = DB::table('ai_suggestions')->where('id', $id)->first();
        $this->apuntar($fila, 'rating', $userId);
        return $fila;
    }

    /** Correcciones de agentes que llevan demasiado esperando a que un responsable las valide (aviso en Ajustes, sin correos). */
    public const DIAS_REVISION = 3;

    public function revisionPendiente(): array
    {
        $q = DB::table('ai_suggestions')->where('rating', 'down')->whereNotNull('correction')->where('correction_status', 'pending');
        $limite = now()->subDays(self::DIAS_REVISION);
        $viejas = (clone $q)->where('rated_at', '<', $limite);
        return [
            'days' => self::DIAS_REVISION, 'pending' => (clone $q)->count(), 'stale' => (clone $viejas)->count(),
            'oldest' => (clone $q)->min('rated_at'),
        ];
    }

    /**
     * ¿Ayuda de verdad? Se mide con lo que ya ocurre en producción, sin pedir nada a los agentes ni enviar nada a nadie:
     *  - cuánto del borrador llega al cliente: «sin cambios» (≥ 90 % parecido al enviado), «con retoques» (50-89 %) o «reescrita» (< 50 %),
     *    y las que acabaron sin respuesta del equipo;
     *  - cuánto tarda el agente desde que hay sugerencia hasta que contesta (mediana);
     *  - tiempo hasta la primera respuesta de los tickets nuevos CON sugerencia frente a los SIN ella (orientativo: no es un ensayo
     *    controlado, los tickets no se reparten al azar).
     * El tiempo AHORRADO como tal no se puede medir sin comparar; esta es la mejor aproximación con datos reales.
     */
    public function medicion(int $dias = 30): array
    {
        $desde = now()->subDays($dias);
        $sugs = DB::table('ai_suggestions')->where('status', 'ready')->whereNotNull('body')->where('created_at', '>=', $desde)
            ->orderByDesc('id')->limit(400)->get(['id', 'ticket_id', 'body', 'created_at', 'used_at', 'rating']);
        $norm = fn (?string $t) => trim((string) preg_replace('/\s+/u', ' ', mb_strtolower(mb_substr(Scrub::firma((string) self::textoPlano($t)), 0, 900))));
        $c = ['sin_cambios' => 0, 'retoques' => 0, 'reescrita' => 0, 'sin_respuesta' => 0];
        $tardan = [];
        foreach ($sugs as $s) {
            $m = DB::table('messages')->where('ticket_id', $s->ticket_id)->where('direction', 'out')->where('is_internal_note', 0)
                ->where('created_at', '>=', $s->created_at)->whereNotNull('body')->orderBy('id')->first(['body', 'created_at']);
            if (! $m) {
                $c['sin_respuesta']++;
                continue;
            }
            similar_text($norm($s->body), $norm($m->body), $pct);
            $c[$pct >= 90 ? 'sin_cambios' : ($pct >= 50 ? 'retoques' : 'reescrita')]++;
            $tardan[] = max(0, (int) round((strtotime((string) $m->created_at) - strtotime((string) $s->created_at)) / 60));
        }
        $mediana = function (array $v) {
            if (! $v) {
                return null;
            }
            sort($v);
            $n = count($v);
            return $n % 2 ? $v[intdiv($n, 2)] : (int) round(($v[$n / 2 - 1] + $v[$n / 2]) / 2);
        };
        // Primera respuesta pública del equipo a cada ticket nuevo (en minutos), con y sin sugerencia lista.
        $filas = DB::table('tickets as t')
            ->join('messages as m', function ($j) {
                $j->on('m.ticket_id', '=', 't.id')->where('m.direction', 'out')->where('m.is_internal_note', 0);
            })
            ->where('t.created_at', '>=', $desde)
            ->groupBy('t.id', 't.created_at')
            ->limit(3000)
            ->selectRaw("t.id, TIMESTAMPDIFF(MINUTE, t.created_at, MIN(m.created_at)) as min_resp, EXISTS(SELECT 1 FROM ai_suggestions a WHERE a.ticket_id = t.id AND a.status = 'ready') as con_ia")
            ->get();
        $con = $filas->where('con_ia', 1)->pluck('min_resp')->map(fn ($x) => max(0, (int) $x))->all();
        $sin = $filas->where('con_ia', 0)->pluck('min_resp')->map(fn ($x) => max(0, (int) $x))->all();
        $total = array_sum($c);

        return [
            'days' => $dias, 'borradores' => $total, 'por_uso' => $c,
            'pct_sin_cambios' => $total ? (int) round($c['sin_cambios'] / $total * 100) : null,
            'pct_aprovechada' => $total ? (int) round(($c['sin_cambios'] + $c['retoques']) / $total * 100) : null,
            'min_hasta_enviar' => $mediana($tardan),
            'tickets_con_ia' => count($con), 'tickets_sin_ia' => count($sin),
            'min_primera_con_ia' => $mediana($con), 'min_primera_sin_ia' => $mediana($sin),
        ];
    }

    /** Historial: cada valoración o revisión queda apuntada (quién, cuándo y qué había sugerido la IA entonces). Nunca se pisa. */
    private function apuntar(object $s, string $tipo, int $userId): void
    {
        DB::table('ai_rating_events')->insert([
            'suggestion_id' => $s->id, 'ticket_id' => $s->ticket_id, 'user_id' => $userId, 'type' => $tipo,
            'rating' => $tipo === 'rating' ? $s->rating : null, 'correction' => $s->correction, 'reason' => $s->reason ?? null,
            'correction_status' => ($s->rating === 'down') ? $s->correction_status : null,
            'ai_body' => $s->body, 'created_at' => now(),
        ]);
    }

    private static function textoPlano(?string $t): ?string
    {
        if ($t === null) {
            return null;
        }
        $h = preg_replace('#<(br|/p|/div|/li)\s*/?>#i', "\n", $t);
        $h = html_entity_decode(strip_tags((string) $h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return mb_substr(trim((string) preg_replace("/\n{3,}/", "\n\n", $h)), 0, 1500);
    }

    /**
     * Historial de valoraciones para Ajustes (support.config): de más nuevo a más antiguo.
     * $filtro: up | down | review | '' (todo). $q: código del ticket. Lo enviado al final es el primer mensaje
     * saliente visible al cliente que el equipo escribió después de la sugerencia.
     *
     * @return array{total:int, items:array}
     */
    public function historial(string $filtro = '', string $q = '', int $desde = 0, int $limite = 30): array
    {
        $b = DB::table('ai_rating_events as e')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('tickets as t', 't.id', '=', 'e.ticket_id')->leftJoin('ai_suggestions as s', 's.id', '=', 'e.suggestion_id');
        if ($filtro === 'review') {
            $b->where('e.type', 'review');
        } elseif (in_array($filtro, ['up', 'down'], true)) {
            $b->where('e.type', 'rating')->where('e.rating', $filtro);
        }
        if (trim($q) !== '') {
            $b->where('t.code', 'like', '%' . trim($q) . '%');
        }
        $total = (clone $b)->count();
        $filas = $b->orderByDesc('e.id')->offset(max(0, $desde))->limit(max(1, min(100, $limite)))
            ->get(['e.id', 'e.type', 'e.rating', 'e.correction', 'e.reason', 'e.correction_status', 'e.ai_body', 'e.created_at', 'e.ticket_id', 'e.suggestion_id',
                'u.name as user_name', 't.code as ticket_code', 't.subject', 's.status as sug_status', 's.created_at as sug_at', 's.used_at']);
        $items = $filas->map(function ($r) {
            $enviado = DB::table('messages as m')->leftJoin('users as a', 'a.id', '=', DB::raw('COALESCE(m.sent_by, m.author_user_id)'))
                ->where('m.ticket_id', $r->ticket_id)->where('m.direction', 'out')->where('m.is_internal_note', 0)
                ->where('m.created_at', '>=', $r->sug_at ?? $r->created_at)->whereNotNull('m.body')->orderBy('m.id')
                ->first(['m.body', 'm.created_at', 'a.name as by']);
            return [
                'id' => (int) $r->id, 'type' => $r->type, 'rating' => $r->rating, 'status' => $r->correction_status,
                'user' => $r->user_name, 'at' => $r->created_at, 'ticket_id' => (int) $r->ticket_id, 'ticket_code' => $r->ticket_code, 'subject' => $r->subject,
                'ai' => $r->sug_status === 'abstained' ? '(la IA no se atrevió a contestar)' : self::textoPlano($r->ai_body),
                'correction' => $r->correction, 'reason' => $r->reason ? (self::MOTIVOS[$r->reason] ?? $r->reason) : null, 'used' => $r->used_at !== null,
                'sent' => $enviado ? ['text' => self::textoPlano($enviado->body), 'by' => $enviado->by, 'at' => $enviado->created_at] : null,
            ];
        })->all();
        return ['total' => $total, 'items' => $items];
    }

    /** Un responsable valida o rechaza una corrección. Solo las validadas alimentan a la IA. */
    public function revisarCorreccion(int $id, string $estado, int $userId): bool
    {
        if (! in_array($estado, ['validated', 'rejected', 'pending'], true)) {
            throw new InvalidArgumentException('Estado no válido');
        }
        $n = DB::table('ai_suggestions')->where('id', $id)->where('rating', 'down')->whereNotNull('correction')->update([
            'correction_status' => $estado,
            'correction_reviewed_by' => $estado === 'pending' ? null : $userId,
            'correction_reviewed_at' => $estado === 'pending' ? null : now(),
            'updated_at' => now(),
        ]);
        $this->knowledge->indexarCorreccion($id);
        if ($n > 0) {
            $this->apuntar(DB::table('ai_suggestions')->where('id', $id)->first(), 'review', $userId);
        }
        return $n > 0;
    }

    public function markUsed(int $id): void
    {
        DB::table('ai_suggestions')->where('id', $id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
    }

    // -- Conocimiento -------------------------------------------------------------

    /** Las últimas mensajes visibles al cliente (sin notas internas), de antiguo a nuevo. */
    private function conversacion(int $ticketId, int $limite = self::MAX_MENSAJES): array
    {
        $rows = DB::table('messages')->where('ticket_id', $ticketId)->where('is_internal_note', 0)
            ->when($this->hasta, fn ($q) => $q->where('id', '<=', $this->hasta))
            ->whereNotNull('body')->orderByDesc('id')->limit($limite)->get(['direction', 'body', 'type', 'media_mime']);

        return $rows->reverse()->map(function ($m) {
            $texto = $this->plano((string) $m->body);
            // El correo arrastra el historial citado, la firma y el aviso legal (WhatsApp y el portal no): se quitan para que la IA lea
            // lo que el cliente acaba de escribir y no se llene el límite de caracteres con ruido. Si no queda nada, se conserva el original.
            $limpio = Scrub::firma($texto);
            $texto = trim($limpio) !== '' ? $limpio : $texto;
            // Pase lo que pase con el correo y el nombre, contraseñas, IBAN, tarjetas y teléfonos no salen hacia el proveedor.
            $texto = Scrub::sensibles($texto);
            // Un audio de WhatsApp no trae texto: se deja una marca para saber que existió (no se transcribe, de momento).
            $esAudio = $m->direction === 'in' && ($m->type === 'audio' || str_starts_with((string) $m->media_mime, 'audio/'));
            return ['role' => $m->direction === 'in' ? 'user' : 'assistant', 'text' => $texto === '' && $esAudio ? self::MARCA_AUDIO : $texto];
        })->filter(fn ($m) => $m['text'] !== '')->values()->all();
    }

    /** ¿Lo último del cliente (tras la última respuesta) son solo audios, sin ningún texto? */
    public static function soloAudios(array $conv): bool
    {
        $ultimos = [];
        foreach (array_reverse($conv) as $m) {
            if ($m['role'] !== 'user') {
                break;
            }
            $ultimos[] = trim((string) $m['text']);
        }
        return $ultimos !== [] && ! array_filter($ultimos, fn ($x) => strcasecmp($x, self::MARCA_AUDIO) !== 0);
    }

    private function ultimaDelCliente(array $conv): string
    {
        foreach (array_reverse($conv) as $m) {
            if ($m['role'] === 'user') {
                return $m['text'];
            }
        }
        return '';
    }

    /**
     * La API exige que la conversación empiece por el usuario y alterne roles: se juntan
     * mensajes seguidos del mismo lado y se descarta lo que precede al primer mensaje del cliente.
     */
    private function comoTurnos(array $conv): array
    {
        $out = [];
        foreach ($conv as $m) {
            if (! $out && $m['role'] !== 'user') {
                continue;
            }
            if ($out && $out[count($out) - 1]['role'] === $m['role']) {
                $out[count($out) - 1]['content'] .= "\n\n" . $m['text'];
            } else {
                $out[] = ['role' => $m['role'], 'content' => $m['text']];
            }
        }
        // Debe terminar en un mensaje del cliente (es a lo que se contesta).
        while ($out && $out[count($out) - 1]['role'] !== 'user') {
            array_pop($out);
        }
        return $out;
    }

    /** FAQs publicadas que más palabras comparten con el texto. Sin coincidencia, ninguna. */
    public function faqsParaTexto(string $texto): array
    {
        $palabras = $this->palabras($texto);
        if (! $palabras) {
            return [];
        }

        $puntuadas = [];
        foreach (DB::table('faqs')->where('active', 1)->get(['id', 'question', 'answer', 'keywords']) as $f) {
            $heno = $this->palabras($f->question . ' ' . $f->keywords);
            $heno2 = $this->palabras($f->answer);
            $p = count(array_intersect($palabras, $heno)) * 3 + count(array_intersect($palabras, $heno2));
            if ($p >= 3) {
                $puntuadas[] = ['p' => $p, 'id' => (int) $f->id, 'question' => $f->question, 'answer' => $this->plano((string) $f->answer)];
            }
        }
        usort($puntuadas, fn ($a, $b) => $b['p'] <=> $a['p']);
        return array_slice($puntuadas, 0, self::MAX_FAQS);
    }

    /**
     * Correcciones de los agentes (lo que la IA debía haber dicho). Primero las de la misma
     * categoría de ticket; luego las más recientes.
     */
    private function ejemplos(?int $categoryId): array
    {
        $rows = DB::table('ai_suggestions as s')->join('tickets as t', 't.id', '=', 's.ticket_id')
            ->where('s.rating', 'down')->whereNotNull('s.correction')->where('s.correction_status', 'validated')
            ->orderByRaw('(t.category_id <=> ?) DESC', [$categoryId])->orderByDesc('s.rated_at')
            ->limit(self::MAX_EJEMPLOS)->get(['s.id', 's.body', 's.correction', 's.trigger_message_id']);

        return $rows->map(function ($s) {
            $msg = DB::table('messages')->where('id', $s->trigger_message_id)->value('body');
            return ['id' => (int) $s->id, 'pregunta' => $this->plano((string) $msg), 'mala' => (string) $s->body, 'correccion' => (string) $s->correction];
        })->all();
    }

    /**
     * Códigos de barras (Code 128) de las fotos que el cliente ha adjuntado en los últimos
     * mensajes. Se leen EN LOCAL (la foto no sale del servidor). Devuelve [códigos, nFotos].
     *
     * @return array{0:string[], 1:int}
     */
    private function leerCodigosDeFotos(int $ticketId): array
    {
        try {
            $ids = DB::table('messages')->where('ticket_id', $ticketId)->where('direction', 'in')->where('is_internal_note', 0)
                ->when($this->hasta, fn ($q) => $q->where('id', '<=', $this->hasta))
                ->orderByDesc('id')->limit(self::MAX_MENSAJES)->pluck('id');
            $fotos = DB::table('attachments')->whereIn('message_id', $ids)->where('mime', 'like', 'image/%')
                ->orderByDesc('id')->limit(4)->get(['path']);
            $lector = new Code128Reader();
            $codigos = [];
            foreach ($fotos as $f) {
                if (! Storage::disk('local')->exists($f->path)) {
                    continue;
                }
                foreach ($lector->leerArchivo(Storage::disk('local')->path($f->path)) as $c) {
                    $codigos[$c] = true;
                }
            }
            return [array_keys($codigos), $fotos->count()];
        } catch (Throwable $e) {
            report($e);
            return [[], 0];
        }
    }

    /**
     * ¿Quién escribe? Flujo: 1) el hotel que dice ser, buscado en el panel; 2) su correo, comprobado en el panel;
     * 3) si coincide, hotel/buffet y estado de antenas y etiquetas (solo lectura, ver HotelsApi); si no coincide o no
     * consta, se le explica con tacto y se deriva a una persona. Sin configurar: nada.
     */
    private function contextoHotel(?string $contactEmail, array $codigos = [], string $textoCliente = '', ?string $nombreContacto = null, ?int $contactId = null): string
    {
        $this->hotelAmbiguo = null;
        $this->estadoCorreo = null;
        $this->hotelLocalizado = false;
        $this->hotelSinApi = false;
        $this->hotelNoEncontrado = false;
        $s = $this->contextoHotelBase($contactEmail, $codigos, $textoCliente, $nombreContacto, $contactId);
        if ($s !== '' && $this->hotelAmbiguo !== null) {
            // El nombre encaja con varios hoteles (p. ej. «Hotel Poseidón» hay más de uno): nunca se asume cuál es.
            // No se enumeran los hoteles al cliente: solo se le pide el nombre completo.
            $s .= "- AMBIGUO: el nombre «" . $this->hotelAmbiguo . "» corresponde a VARIOS hoteles. NO asumas cuál es y NO des datos de ninguno: "
                . "pregúntale simplemente el nombre completo del hotel antes de seguir. NO le digas que hay varios hoteles con ese nombre ni nombres otros: "
                . "quien escribe podría no ser del hotel y no debe saber qué hoteles existen.\n";
        }
        return $s;
    }

    /** Parte del dominio de un correo de empresa que sirve de nombre («info@hotelestival.com» → «estival»); null en correos genéricos. */
    public static function nombreDeDominio(string $email): ?string
    {
        $d = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));
        $partes = explode('.', $d);
        if (count($partes) < 2) {
            return null;
        }
        $etq = $partes[count($partes) - 2];
        if (in_array($etq, ['gmail', 'googlemail', 'hotmail', 'outlook', 'live', 'msn', 'yahoo', 'icloud', 'me', 'proton', 'protonmail', 'telefonica', 'movistar', 'terra', 'ono', 'orange', 'aemegroup'], true)) {
            return null;
        }
        $n = preg_replace('/^(hotel|grupo)/', '', preg_replace('/[^a-z0-9]/', '', $etq));
        return strlen((string) $n) >= 5 ? $n : null;
    }

    /**
     * En WhatsApp muchos contactos se guardan como «Manolo Hyatt Calas Mallorca» (nombre de pila + hotel). Busca en el panel con las palabras
     * del nombre (salvo la primera, que suele ser el nombre de pila) y devuelve el hotel solo si UNO encaja con al menos dos de ellas.
     * Es una pista sin confirmar: nunca sustituye a la verificación del correo.
     */
    private function hotelPorNombreDeContacto(HotelsApi $api, string $nombre): ?string
    {
        $pal = array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', HotelsApi::norm($nombre)) ?: [], fn ($w) => mb_strlen($w) >= 4 && ! in_array($w, ['hotel', 'hoteles', 'resort', 'grupo', 'recepcion', 'direccion', 'cocina', 'chef'], true)));
        if (count($pal) < 3) {
            return null;   // con el nombre de pila y una sola palabra más no hay base para suponer nada
        }
        $pal = array_slice($pal, 1, 4);
        $vistos = [];
        foreach ($pal as $w) {
            foreach ($api->buscarHoteles($w) ?? [] as $h) {
                $vistos[$h['id'] ?: $h['nombre']] = $h;
            }
        }
        $mejores = [];
        $max = 0;
        foreach ($vistos as $h) {
            $n = ' ' . HotelsApi::norm($h['nombre']) . ' ';
            $puntos = count(array_filter($pal, fn ($w) => str_contains($n, $w)));
            if ($puntos > $max) { $max = $puntos; $mejores = []; }
            if ($puntos === $max) { $mejores[] = $h['nombre']; }
        }
        return ($max >= 2 && count($mejores) === 1) ? $mejores[0] : null;
    }

    /** ¿Hay UN solo hotel en el panel cuyo nombre (sin espacios) contiene el nombre del dominio? Devuelve su nombre o null. */
    private function hotelPorDominio(HotelsApi $api, string $email): ?string
    {
        $n = self::nombreDeDominio($email);
        if ($n === null) {
            return null;
        }
        $r = $api->buscarHoteles($n) ?? [];
        $r = array_values(array_filter($r, fn ($h) => str_contains(str_replace(' ', '', HotelsApi::norm($h['nombre'])), $n)));
        return count($r) === 1 ? $r[0]['nombre'] : null;
    }

    private function contextoHotelBase(?string $contactEmail, array $codigos = [], string $textoCliente = '', ?string $nombreContacto = null, ?int $contactId = null): string
    {
        $api = app(HotelsApi::class);
        if (! $api->configured()) {
            $this->paso('hotel', null, 'AdminHoteles no está configurado: no se consulta el hotel');
            return '';
        }
        // Correo: el que el cliente dice usar (si lo escribe) o, si no, el del contacto del ticket.
        preg_match_all('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $textoCliente, $m);
        $e = trim((string) ($m[0][0] ?? $contactEmail));
        // Un pedido de material no necesita saber si las antenas están en línea ni qué rol tiene el usuario: no se consulta ni se cuenta.
        $pedido = self::esPedido($textoCliente);
        // Hotel: el que el cliente nombra, buscado en el inventario del panel.
        $this->avisar('Buscando el hotel en AdminHoteles…');
        $nombres = $this->nombresDeHotel($textoCliente);
        // El mensaje no nombra hotel pero este contacto ya fue verificado antes (vínculo vigente, 12 meses): se parte de ese hotel. Solo evita volver a
        // preguntarlo: el correo se comprueba igual en AdminHoteles cada vez, y si ya no figura o está desactivado no se le atiende.
        $memoria = $nombres ? null : ContactoHotel::vigente($contactId);
        if ($memoria) {
            $nombres = [$memoria->name];
        }
        // Usuario de varios hoteles de una misma marca (enlace por marca): no hay un hotel que dar por sabido, solo la marca.
        $marcaMem = (! $nombres && ! $memoria) ? ContactoHotel::vigenteMarca($contactId) : null;
        if ($marcaMem) {
            $this->paso('hotel', null, 'Contacto de varios hoteles de la marca «' . $marcaMem->name . '» (hotel concreto sin confirmar)');
        }
        // Sin «Hotel X» explícito (p. ej. solo la firma «Barceló Benidorm Beach»): se prueban nombres propios del mensaje y solo
        // valen los hoteles cuyo nombre contiene el buscado (o al revés), para no arrastrar resultados sueltos del buscador.
        $porMayusculas = ! $nombres;
        if ($porMayusculas) {
            $nombres = $this->nombresPorMayusculas($textoCliente);
        }
        $dir = [];
        $unicos = [];
        $ambiguos = [];
        $dirFalla = false;
        foreach (array_slice($nombres, 0, 3) as $n) {
            $r = $api->buscarHoteles($n);
            if ($r === null) {
                $dirFalla = true;
                continue;
            }
            if ($porMayusculas) {
                $nn = HotelsApi::norm($n);
                $kw = HotelsApi::palabrasClave($n);
                $r = array_values(array_filter($r, function ($h) use ($nn, $kw) {
                    $hn = HotelsApi::norm($h['nombre']);
                    if ($nn !== '' && (str_contains($hn, $nn) || str_contains($nn, $hn))) {
                        return true;
                    }
                    // «Bahía Príncipe Explore Turquesa» ~ «Bahia Principe Grand Turquesa»: la mayoría de las palabras clave coinciden.
                    $c = count(array_filter($kw, fn ($w) => str_contains(' ' . $hn . ' ', " $w ")));
                    return count($kw) >= 2 && $c >= 2 && $c >= count($kw) - 1;
                }));
            }
            $dir = array_merge($dir, $r);
            // Varios resultados y ninguno se llama exactamente así: el cliente no ha dicho cuál de ellos es.
            $exacto = array_filter($r, fn ($h) => HotelsApi::norm($h['nombre']) === HotelsApi::norm($n));
            if (count($r) === 1) {
                $unicos[] = [HotelsApi::norm($n), $r[0]];
            }
            if (count($r) >= 2 && ! $exacto) {
                $ambiguos[] = $n;
            }
        }
        // Frases con mayúsculas que no son ningún hotel (el asunto «Revisión Platos», una firma…): no son un hotel «que no consta», solo ruido.
        if (($porMayusculas || $memoria) && ! $dir && ! $dirFalla) {
            $nombres = [];
            $ambiguos = [];
        }
        // Un nombre suelto («Playas») que está dentro de otro nombre ya resuelto a UN solo hotel («Playas de Guardamar») no es ambiguo.
        foreach ($ambiguos as $n) {
            $nn = HotelsApi::norm($n);
            $cubierto = array_filter($unicos, fn ($u) => $u[0] !== $nn && str_contains(' ' . $u[0] . ' ', ' ' . $nn . ' '));
            if (! $cubierto && $this->hotelAmbiguo === null) {
                $this->hotelAmbiguo = $n;
            }
        }
        if ($this->hotelAmbiguo !== null) {
            $this->paso('hotel', false, 'Hotel ambiguo: «' . $this->hotelAmbiguo . '» corresponde a varios hoteles');
        } elseif ($dir) {
            $this->paso('hotel', true, ($memoria ? 'Hotel recordado (ticket anterior, confirmado el ' . date('d/m/Y', strtotime($memoria->verificada)) . '): ' : 'Hotel localizado: ') . $dir[0]['nombre'] . (! empty($dir[0]['grupo']) ? ' (' . $dir[0]['grupo'] . ')' : '') . (count($dir) > 1 ? ' · +' . (count($dir) - 1) . ' coincidencias' : ''), ['nombre' => $dir[0]['nombre'], 'grupo' => $dir[0]['grupo'] ?? null, 'otras' => count($dir) - 1]);
        } elseif ($nombres) {
            $this->paso('hotel', false, 'Hotel no localizado: «' . $nombres[0] . '»' . ($dirFalla ? ' (el panel no respondió)' : ''));
        } else {
            $this->paso('hotel', null, 'El cliente no nombra ningún hotel');
        }
        $this->avisar('Comprobando el correo en AdminHoteles…');
        $v = $api->verificar($e);
        // Con el hotel localizado (uno solo, con id) y el lookup global sin resultado útil —no figura, las apps no contestaron o figura
        // pero no en ese hotel—, se pregunta directamente por ese hotel: es una sola app y no depende de las demás.
        if ($dir && count($dir) === 1 && $this->hotelAmbiguo === null && trim((string) ($dir[0]['id'] ?? '')) !== ''
            && ! HotelsApi::sinApi($dir) && in_array($v['estado'], ['incompleto', 'no_encontrado', 'encontrado'], true)
            && ! ($v['estado'] === 'encontrado' && $this->coincideHotel($dir, $v['sitios']))) {
            $enHotel = $api->usuarioEnHotel((string) $dir[0]['id'], $e);
            if ($enHotel) {
                $v = ['estado' => 'encontrado', 'sitios' => array_merge($enHotel, $v['estado'] === 'encontrado' ? $v['sitios'] : [])];
                $this->paso('correo', null, 'El correo se ha comprobado directamente en el hotel localizado');
            }
        }
        // Un usuario DESACTIVADO no cuenta como verificado: solo valen los sitios donde está activo (y si en ninguno, es «desactivado»).
        $todos = $v['sitios'];
        $v['sitios'] = array_values(array_filter($todos, fn ($x) => ($x['activo'] ?? true) !== false));
        if ($v['estado'] === 'encontrado' && $todos && ! $v['sitios']) {
            $v['estado'] = 'desactivado';
        }
        // La comparación con el hotel se hace con TODOS los sitios (un usuario de cadena trae decenas): solo se recorta lo que se muestra.
        $sitiosTodos = $v['sitios'];
        $sitios = array_slice($sitiosTodos, 0, 4);
        $this->hotelLocalizado = (bool) $dir;
        $this->hotelSinApi = $dir && $this->hotelAmbiguo === null && HotelsApi::sinApi($dir);
        if ($this->hotelSinApi) {
            $this->paso('hotel', null, 'Hotel de una app que aún no está en la API de soporte: no se puede verificar, lo lleva una persona');
        }
        $this->hotelNoEncontrado = ! $dir && $nombres && ! $dirFalla;
        $this->estadoCorreo = ($v['estado'] === 'encontrado' && (! $dir || $this->coincideHotel($dir, $sitiosTodos))) ? 'verificado' : (string) $v['estado'];
        // Verificado y de UN solo hotel: se recuerda para no volver a preguntarlo en el próximo ticket.
        if ($this->estadoCorreo === 'verificado' && $contactId && $v['sitios']) {
            $nuevo = ContactoHotel::recordar($contactId, $v['sitios'], (string) ($dir[0]['grupo'] ?? ''));
            if ($nuevo) {
                $this->paso('hotel', true, 'Hotel (o marca, si tiene varios) guardado para los próximos tickets de este contacto: ' . $nuevo);
            }
            // Contacto sin correo (WhatsApp) que lo ha dado y AdminHoteles lo confirma: se guarda en su ficha.
            if (mb_strtolower($e) !== mb_strtolower(trim((string) $contactEmail))) {
                $g = ContactoHotel::guardarCorreo($contactId, $e);
                if (($g['estado'] ?? '') === 'guardado') {
                    $this->paso('correo', true, "Correo $e guardado en la ficha del contacto (lo dio el cliente y está verificado)");
                } elseif (($g['estado'] ?? '') === 'duplicado') {
                    $this->paso('correo', null, "El correo $e ya es de otra ficha (#{$g['otro']}): conviene unirlas en Contactos");
                }
            }
        }
        // Traza para el agente: hotel y correo (lo que ve el panel de la sugerencia).
        if ($v['estado'] === 'sin_correo') {
            $this->paso('correo', null, 'Sin correo que comprobar');
        } elseif ($v['estado'] === 'incompleto') {
            $this->paso('correo', null, "No se pudo comprobar el correo $e ahora (el panel no respondió)");
        } elseif ($v['estado'] === 'desactivado') {
            $this->paso('correo', false, "El correo $e figura, pero su usuario está DESACTIVADO: no se le atiende como usuario");
        } elseif ($v['estado'] === 'no_encontrado') {
            $this->paso('correo', false, "El correo $e no figura como usuario de la aplicación");
        } elseif ($dir && ! $this->coincideHotel($dir, $sitiosTodos)) {
            $this->paso('correo', false, "El correo $e figura, pero no asociado al hotel que dice");
        } else {
            $this->paso('correo', true, "Correo $e verificado en: " . implode('; ', array_map(fn ($x) => trim($x['hotel'] . ' / ' . $x['buffet'], ' /'), $sitios)), ['correo' => $e, 'sitios' => array_map(fn ($x) => trim($x['hotel'] . ' / ' . $x['buffet'], ' /'), $sitios)]);
        }
        $cab = "\nVERIFICACIÓN DEL CLIENTE (consulta automática de solo lectura: no digas de dónde sale)\n";
        $comun = "- NO des datos de ningún hotel ni de su estado, y NO reveles a qué hotel pertenece un correo.\n";
        $derivar = "- Termina diciendo con amabilidad que un compañero del equipo revisará su caso y le atenderá personalmente; no sigas con el diagnóstico.\n";
        $pedir = "- Pídele con tacto que confirme el nombre del hotel y el correo con el que accede a la aplicación.\n";
        $tacto = "- Sin acusar ni sonar a sospecha: es lo normal que alguien escriba con otro correo.\n";

        // Un pedido de material no depende de datos del hotel: si el correo no se pudo comprobar (o no hay), se atiende igual, sin derivar por eso.
        if ($pedido && in_array($v['estado'], ['sin_correo', 'incompleto'], true)) {
            return $cab . "- El cliente pide material. Eso no depende de datos de su hotel: NO hace falta verificar su correo ni derivar por eso. Explícale cómo hacer el pedido según las reglas de soporte.
" . $comun;
        }
        if ($v['estado'] === 'sin_correo') {
            if (! $nombres) {
                $pista = $this->hotelPorNombreDeContacto($api, (string) $nombreContacto);
                if ($pista) {
                    $this->paso('hotel', null, 'Pista por el nombre del contacto: «' . $pista . '» (sin confirmar)');
                }
                return $cab . "- Aún no sabemos de qué hotel es ni con qué correo accede. Pregunta PRIMERO el nombre del hotel y, a continuación, el correo con el que accede a la aplicación.\n"
                    . ($pista ? "- PISTA SIN CONFIRMAR: el nombre con el que figura este contacto se parece al hotel «{$pista}». NO lo afirmes ni des datos de él: úsalo solo para preguntar «¿Se refiere a {$pista}?» al pedirle el nombre del hotel.\n" : '')
                    . ($marcaMem ? "- Este contacto ya nos escribió antes y figura con acceso a varios hoteles de la marca «{$marcaMem->name}»: NO supongas cuál. Pregunta de cuál de sus hoteles habla.\n" : '')
                    . $comun;
            }
            if ($dir) {
                if (! $pedido && ($antenas = $this->antenasSinVerificar($api, $dir, $nombres, $unicos))) {
                    return $cab . $antenas;
                }
                return $cab . "- El cliente " . ($memoria ? 'ya nos escribió antes y figura como de «' : 'dice ser de «') . $dir[0]['nombre'] . "». Falta comprobar su correo.\n"
                    . "- VERIFICAR PRIMERO, EXPLICAR DESPUÉS: si lo que pide implica CONFIGURAR algo de su buffet en la aplicación (crear o editar posiciones, isletas, etiquetas, servicios, ruedas…), NO expliques todavía los pasos: pídele el correo con el que accede a la aplicación (el hotel ya lo tienes) y dile que, en cuanto lo confirme, le indicas cómo hacerlo. REGLA DE LA DIRECCIÓN: en un hotel SIEMPRE se verifica antes de explicar, pedir otros datos o derivar, también para una duda simple de uso. Pide el correo siempre que necesites datos concretos de ese hotel (usuarios, permisos, etiquetas concretas).\n" . $comun;
            }
            return $cab . "- El hotel que nombra no se localiza en nuestro sistema" . ($dirFalla ? ' (no se pudo consultar ahora)' : '') . ". Pídele el nombre exacto del hotel y el correo con el que accede a la aplicación.\n" . $comun . $derivar;
        }
        if ($v['estado'] === 'incompleto') {
            // El correo no se pudo comprobar, pero si el hotel viene nombrado y se localiza, el estado de las antenas se cuenta igual.
            if (! $pedido && $dir && $nombres && ($antenas = $this->antenasSinVerificar($api, $dir, $nombres, $unicos))) {
                return $cab . "- No se ha podido comprobar el correo ($e) ahora mismo. NO afirmes que el correo no pertenece al hotel ni que no figura.\n" . $antenas;
            }
            return $cab . "- No se ha podido comprobar el correo ($e) ahora mismo. NO afirmes que el correo no pertenece al hotel ni que no figura.\n"
                . "- Di que un compañero comprobará sus datos y le atenderá. Si aún no sabemos el hotel, pregúntalo.\n" . $comun;
        }
        if ($v['estado'] === 'desactivado') {
            return $cab . "- El correo del cliente ($e) figura, pero su usuario está DESACTIVADO en la aplicación: de momento NO es un usuario con acceso. NO consultes ni des datos de ningún hotel, antena ni etiqueta, y NO expliques pasos.\n"
                . "- Dile con tacto que un compañero del equipo revisará su caso y le atenderá personalmente (reactivar un usuario lo hace una persona).\n" . $comun . $derivar;
        }
        if ($v['estado'] === 'no_encontrado') {
            // Correo que no figura: el dominio (si es de empresa) puede orientar sobre el hotel. Es solo una pista, nunca una verificación.
            $pista = (! $dir && ! $nombres) ? ($this->hotelPorDominio($api, $e) ?? $this->hotelPorNombreDeContacto($api, (string) $nombreContacto)) : null;
            if ($pista) {
                $this->paso('hotel', null, 'Pista por el dominio del correo: «' . $pista . '» (sin confirmar)');
            }
            return $cab . "- El correo del cliente ($e) NO figura como usuario en la aplicación de hoteles.\n"
                . ($pista ? "- PISTA SIN CONFIRMAR: el dominio de su correo se parece al hotel «$pista». NO lo afirmes, NO des datos de él ni cambies lo que sigue: úsalo solo para preguntar «¿Se refiere a $pista?» al pedirle el nombre del hotel.\n" : '')
                . "- Explícaselo con tacto: con ese correo no podemos asociarle a un hotel en nuestro sistema.\n" . $pedir . $tacto . $comun . $derivar;
        }
        // encontrado: ¿coincide con el hotel que dice ser?
        if ($dir && ! $this->coincideHotel($dir, $sitiosTodos)) {
            return $cab . "- El cliente dice ser de «" . $dir[0]['nombre'] . "», pero su correo ($e) no figura asociado a ese hotel en la aplicación.\n"
                . "- Explícaselo con tacto: con el correo que nos indica no podemos asociarle a ese hotel en nuestro sistema.\n" . $pedir . $tacto . $comun . $derivar;
        }
        $s = $cab
            . "- VERIFICADO: el correo del cliente ($e) figura como usuario activo de la aplicación de hoteles en: "
            . implode('; ', array_map(fn ($x) => trim($x['hotel'] . ' / ' . $x['buffet'], ' /'), $sitios)) . ".\n";
        if (count($sitios) > 1) {
            $s .= "- Figura en varios sitios: si la consulta depende del hotel, pregunta de cuál habla.\n";
        }
        // Rol del usuario (la API no da los permisos concretos, solo el nombre del rol).
        $roles = $pedido ? [] : array_values(array_unique(array_filter(array_map(fn ($x) => $x['rol'] ?? null, $sitios))));
        if ($roles) {
            $this->paso('rol', true, 'Rol de su usuario: ' . implode(', ', array_map(fn ($r) => '«' . $r . '»', $roles)) . (array_filter($sitios, fn ($x) => ($x['activo'] ?? true) === false) ? ' · usuario DESACTIVADO' : ' · usuario activo'));
        }
        if ($roles) {
            $s .= '- Rol de su usuario en la aplicación: ' . implode(', ', array_map(fn ($r) => '«' . $r . '»', $roles))
                . ".\n- USUARIOS Y PERMISOS: si pide acceso o permisos (crear platos, modificar menús, forzar, etc.), dile qué rol tiene hoy y, con la documentación de roles, qué permite ese tipo de rol (sin inventar permisos que no sepas). Si el rol parece suficiente, indícale dónde se hace en la aplicación. Si necesita más permisos, los cambia el propio hotel: Ajustes > Roles (editar el rol y marcar los permisos; requiere el permiso «editar roles») o asignarle otro rol en Usuarios > Editar; si su usuario no puede, que lo haga un administrador de su hotel (Dirección). Crear o reactivar un usuario también lo hace el hotel (Usuarios > Crear nuevo usuario; reactivar: Usuarios > Editar > Estado). Explícaselo con la documentación y dile antes su rol actual («Su usuario tiene hoy el rol X»); solo DERIVA si en su hotel nadie puede hacerlo. NUNCA afirmes qué permisos incluye o no incluye su rol concreto: los roles los personaliza cada hotel y tú solo sabes el NOMBRE del rol. No digas que ya está hecho y no facilites usuarios ni contraseñas.\n";
        }
        if (! $pedido && array_filter($sitios, fn ($x) => ($x['activo'] ?? true) === false)) {
            $s .= "- Su usuario figura DESACTIVADO en la aplicación: díselo con tacto; reactivarlo lo hace una persona del equipo (deriva con la frase corta).\n";
        }
        foreach ($pedido ? [] : $sitios as $x) {
            $an = $api->antenas($x);
            if ($an === null) {
                continue;
            }
            $this->pasoAntenas((string) $x['buffet'], $an);
            $caidas = array_values(array_filter($an, fn ($a) => ! $a['online']));
            $s .= '- Antenas de ' . $x['buffet'] . ': ' . count($an) . ' en total; ' . ($caidas
                ? 'SIN CONEXIÓN: ' . implode(', ', array_map(fn ($a) => $a['name'] . ($a['offline_since'] ? ' (desde ' . substr((string) $a['offline_since'], 0, 16) . ')' : ''), $caidas)) . ".\n"
                : "todas con conexión.\n");
        }
        // Estado de las etiquetas concretas que menciona el cliente (código leído de la foto o escrito en el mensaje).
        foreach ($pedido ? [] : array_slice($codigos, 0, 5) as $c) {
            $hallada = null;
            foreach ($sitios as $x) {
                if ($hallada = $api->etiqueta($x, $c)) {
                    $hallada['buffet'] = $x['buffet'];
                    break;
                }
            }
            $this->paso('etiqueta', $hallada ? (bool) $hallada['online'] : null, $hallada
                ? "Etiqueta $c (" . $hallada['buffet'] . '): ' . ($hallada['online'] ? 'en línea' : 'SIN CONEXIÓN') . ($hallada['battery'] !== null ? ', batería ' . $hallada['battery'] . ' %' : '')
                : "Etiqueta $c: no localizada en el panel");
            if ($hallada) {
                $ap = $hallada['antenna'];
                $s .= "- Etiqueta {$c} (" . $hallada['buffet'] . '): ' . ($hallada['online'] ? 'en línea' : 'SIN CONEXIÓN')
                    . ($hallada['battery'] !== null ? ', batería ' . $hallada['battery'] . ' %' : '')
                    . ($hallada['signal'] !== null ? ', señal ' . $hallada['signal'] . ' dBm' : '')
                    . ($hallada['updated_at'] ? ', última actualización ' . substr(str_replace('T', ' ', (string) $hallada['updated_at']), 0, 16) : '')
                    . ($ap ? ', la sirve la antena ' . $ap['name'] . ($ap['online'] ? ' (con conexión)' : ' (SIN CONEXIÓN)') : '') . ".\n";
            }
        }
        // Posiciones concretas que menciona el cliente (código tipo CL02-05): existe, está activa, qué etiqueta y qué plato tiene.
        if (! $pedido && $sitios) {
            preg_match_all('/\b[A-Z]{1,4}\d{1,3}-\d{1,3}\b/', $textoCliente, $pm);
            foreach (array_slice(array_unique($pm[0] ?? []), 0, 3) as $cp) {
                $p = null;
                foreach ($sitios as $x) {
                    if (($x['buffet_id'] ?? '') !== '' && ($p = $api->posicion($x, $cp))) {
                        $p['buffet'] = $x['buffet'];
                        break;
                    }
                }
                if (! $p) {
                    continue;
                }
                $this->paso('posicion', null, "Posición $cp (" . $p['buffet'] . '): ' . ($p['activa'] === false ? 'INACTIVA' : 'activa') . ($p['etiqueta'] ? ', etiqueta ' . $p['etiqueta'] : ', SIN etiqueta') . ($p['plato'] ? ', plato «' . $p['plato'] . '»' : ', sin plato'));
                $s .= "- Posición {$cp} (" . $p['buffet'] . '): ' . ($p['activa'] === false ? 'INACTIVA' : 'activa') . ($p['etiqueta'] ? ', etiqueta ' . $p['etiqueta'] : ', sin etiqueta vinculada') . ($p['plato'] ? ', plato «' . $p['plato'] . '»' : ', sin plato asignado') . ".\n";
            }
        }
        if ($pedido) {
            $this->paso('filtro', null, 'Es un pedido de material: no se consultan antenas ni rol');
            return $s;
        }
        return $s . self::REGLA_ANTENAS . "- Si una etiqueta tiene batería baja, sigue la guía de pilas (no tramites material).\n";
    }

    /** ¿Pide material (pedido, soportes, presupuesto…) y no cuenta ningún fallo? Entonces las antenas y el rol no pintan nada. */
    private static function esPedido(string $t): bool
    {
        $fallo = '/antena|conexi[oó]n|no (se )?(actualiz|cambia|muestra|funcion|enciende|responde|reaccion)|se cambian solas|fuera de servicio|ca[ií]d[ao]|sin (se[ñn]al|conexi)|offline|no (puedo|me deja)|(da|sale|aparece|muestra)n? (un )?error|mensaje de error|falla|problema|incidencia|aver[ií]a/iu';
        $pide = '/pedido|pedir|necesit(o|amos) (más )?(etiquetas|soportes|material)|soportes|material|presupuesto|factura|albar[aá]n|env[ií]o de|comprar|reposici[oó]n/iu';
        return preg_match($pide, $t) === 1 && preg_match($fallo, $t) !== 1;
    }

    /** Nombres de hotel que el cliente menciona («Hotel Alua Soul Menorca», «AluaSun Lago Park»…): candidatos para buscar en el panel. */
    private function nombresDeHotel(string $texto): array
    {
        $out = [];
        $cap = '[A-ZÁÉÍÓÚÑ][\wáéíóúñ\'\-]*';
        if (preg_match_all('/\b(?i:hotel|resort|restaurante|aparthotel|apartamentos|complejo)[ 	]+((?:' . $cap . '[ 	]*){1,4})/u', $texto, $m)) {
            foreach ($m[1] as $n) {
                $out[trim($n)] = true;
            }
        }
        if (preg_match_all('/\b(alua[\wáéíóúñ]*(?:\s+' . $cap . '){0,3})/iu', $texto, $m)) {   // la cadena más habitual
            foreach ($m[1] as $n) {
                $out[trim($n)] = true;
            }
        }
        return array_values(array_filter(array_keys($out), fn ($n) => mb_strlen($n) >= 4));
    }

    /**
     * Estado de las antenas del hotel que el cliente nombra, SIN haber comprobado su correo: no es un dato sensible y se puede contar
     * (decisión de Juan, 13-nov). Todo lo demás (usuarios, permisos, etiquetas concretas) sigue pidiendo el correo antes.
     * Devuelve las líneas de contexto, o null si no hay un hotel claro o no se pudieron leer las antenas.
     *
     * @param array<int, array{id:string, nombre:string, grupo:string}> $dir hoteles encontrados
     * @param array<int, string> $nombres nombres de hotel que dijo el cliente
     * @param array<int, array{0:string, 1:array}> $unicos nombres que dieron UN solo hotel
     */
    private function antenasSinVerificar(HotelsApi $api, array $dir, array $nombres, array $unicos): ?string
    {
        $elegido = null;
        if ($this->hotelAmbiguo === null) {
            $nombresNorm = array_map(fn ($x) => HotelsApi::norm($x), $nombres);
            foreach ($dir as $h) {
                if (in_array(HotelsApi::norm($h['nombre']), $nombresNorm, true)) { $elegido = $h; break; }
            }
            $elegido ??= $unicos ? $unicos[0][1] : (count($dir) === 1 ? $dir[0] : null);
        }
        $lineas = '';
        foreach ($elegido && ($elegido['id'] ?? '') !== '' ? ($api->buffetsDeHotel($elegido['id']) ?? []) : [] as $x) {
            $an = $api->antenas($x);
            if ($an === null) {
                continue;
            }
            $this->pasoAntenas((string) ($x['buffet'] ?: $elegido['nombre']), $an);
            $caidas = array_values(array_filter($an, fn ($a) => ! $a['online']));
            $lineas .= '- Antenas de ' . ($x['buffet'] ?: $elegido['nombre']) . ': ' . count($an) . ' en total; ' . ($caidas
                ? 'SIN CONEXIÓN: ' . implode(', ', array_map(fn ($a) => $a['name'] . ($a['offline_since'] ? ' (desde ' . substr((string) $a['offline_since'], 0, 16) . ')' : ''), $caidas)) . ".\n"
                : "todas con conexión.\n");
        }
        if ($lineas === '') {
            return null;
        }
        return "- El cliente dice ser de «" . $elegido['nombre'] . "». Su correo aún no está comprobado.\n"
            . "- El ESTADO DE LAS ANTENAS no es un dato sensible: puedes contárselo ya, sin pedir el correo para eso:\n" . $lineas
            . self::REGLA_ANTENAS
            . "- Para cualquier OTRO dato del hotel (usuarios, permisos, etiquetas concretas) pide antes el correo con el que accede a la aplicación y NO reveles a qué hotel pertenece un correo.\n";
    }

    /** Nombres propios de 2-4 palabras del mensaje (firmas tipo «Barceló Benidorm Beach»), sin saludos ni cargos: candidatos a hotel. */
    private function nombresPorMayusculas(string $texto): array
    {
        $cap = '[A-ZÁÉÍÓÚÑ][\wáéíóúñ\'\-]*';
        $paradas = array_flip(explode(' ', 'buenos buenas hola saludos gracias por favor un una el la los las estimados estimado atentamente cordialmente subdirector director jefe responsable manager deputy telf tel correo email aeme group etiqueta etiquetas antena antenas buffet cliente muchas mil nos os les le si no ya que desde para con sin sobre tenemos tengo hemos'));
        $out = [];
        if (preg_match_all('/(?<![\wáéíóúñ])(' . $cap . '(?:[ \t]+(?:(?:de|del|la|las|los|y|&)[ \t]+)?' . $cap . '){1,3})/u', $texto, $m)) {
            foreach ($m[1] as $frase) {
                $pal = preg_split('/[ \t]+/', trim($frase));
                while ($pal && isset($paradas[mb_strtolower($pal[0])])) { array_shift($pal); }
                while ($pal && isset($paradas[mb_strtolower(end($pal))])) { array_pop($pal); }
                $n = implode(' ', $pal);
                if (count($pal) >= 2 && mb_strlen($n) >= 6) {
                    $out[$n] = true;
                }
            }
        }
        // Un solo nombre propio tras «en/de/del» («forzar el menú en Islantilla»): sirve como pista; la búsqueda decide si es un hotel.
        if (preg_match_all('/(?<![\wáéíóúñ])(?:en|de|del|desde)[ \t]+(' . $cap . ')(?![\wáéíóúñ]*[ \t]+' . $cap . ')/u', $texto, $m)) {
            foreach ($m[1] as $w) {
                if (mb_strlen($w) >= 6 && ! isset($paradas[mb_strtolower($w)])) {
                    $out[$w] = true;
                }
            }
        }
        return array_slice(array_keys($out), 0, 4);
    }

    /** ¿Alguno de los hoteles buscados es uno de los sitios donde figura el correo? (nombres sin tildes, uno dentro de otro) */
    private function coincideHotel(array $dir, array $sitios): bool
    {
        foreach ($dir as $d) {
            $a = HotelsApi::norm($d['nombre']);
            foreach ($sitios as $x) {
                // Con el id de AdminHoteles a los dos lados manda el id (los nombres se parecen y se repiten); sin él, el nombre.
                $idD = trim((string) ($d['id'] ?? ''));
                $idX = trim((string) ($x['hotel_id'] ?? ''));
                if ($idD !== '' && $idX !== '') {
                    if ($idD === $idX) {
                        return true;
                    }
                    continue;
                }
                foreach ([$x['hotel'], $x['buffet']] as $nombre) {
                    $b = HotelsApi::norm((string) $nombre);
                    if ($a !== '' && $b !== '' && (str_contains($a, $b) || str_contains($b, $a))) {
                        return true;
                    }
                }
            }
        }
        return false;
    }

    /** Códigos de etiqueta (9 letras/números) o MAC (12 hex) escritos en un texto. */
    private function codigosEnTexto(string $texto): array
    {
        preg_match_all('/\b[A-Za-z0-9]{9,12}\b/', $texto, $m);
        $out = [];
        foreach ($m[0] as $c) {
            $c = strtoupper($c);
            if (preg_match('/^[0-9A-F]{12}$/', $c) || (strlen($c) === 9 && preg_match('/\d/', $c) && preg_match('/[A-Z]/', $c))) {
                $out[$c] = true;
            }
        }
        return array_keys($out);
    }

    /** Saludo según la hora y si el equipo ya contestó en este ticket (entonces no se repite presentación ni saludo). */
    private function saludo(object $t): string
    {
        $yaRespondio = property_exists($t, 'yaRespondio') ? $t->yaRespondio : DB::table('messages')->where('ticket_id', $t->id)->where('direction', 'out')
            ->where('is_internal_note', 0)->whereNotNull('author_user_id')
            ->when($this->hasta, fn ($q) => $q->where('id', '<', $this->hasta))->exists();
        if ($yaRespondio) {
            return "SALUDO Y PRESENTACIÓN (anula lo que digan las instrucciones anteriores)\n"
                . "- El equipo ya contestó antes en esta conversación: NO saludes ni te presentes. Entra directamente en la respuesta.\n"
                . "- Tampoco repitas la firma ni la despedida larga; basta una frase breve de cierre si procede.";
        }
        $h = (int) now()->format('G');
        $f = $h >= 6 && $h < 14 ? 'Buenos días' : ($h < 21 && $h >= 14 ? 'Buenas tardes' : 'Buenas noches');
        return "SALUDO Y PRESENTACIÓN (anula lo que digan las instrucciones anteriores)\n"
            . "- Es el primer mensaje del equipo en esta conversación. Ahora mismo toca «{$f}»: empieza con «{$f}.» (en el idioma del cliente, manteniendo el saludo adecuado a la hora). NO te presentes ni digas «le hablo de AEME Group»: la firma ya dice quién eres.";
    }

    /** Reglas de decisión del asistente (resources/ai/prompt-reglas.md), con las marcas del código resueltas. */
    private function reglas(): string
    {
        $ruta = resource_path('ai/prompt-reglas.md');
        $txt = is_file($ruta) ? trim((string) file_get_contents($ruta)) : '';
        return strtr($txt, ['{{NO_SABE}}' => self::NO_SABE, '{{AUDIO}}' => self::MARCA_AUDIO]);
    }

    private function instrucciones(object $t, array $faqs, array $ejemplos, array $casos = []): string
    {
        $ruta = resource_path('ai/prompt-soporte.md');
        $base = is_file($ruta) ? trim((string) file_get_contents($ruta)) : '';
        if ($base === '') {
            $base = "Eres el asistente del equipo de soporte de AEME. Tratas al cliente de usted, en su idioma, con frases cortas y sin inventar nada. Redactas solo borradores para revisión humana.";
        }
        $s = $base . "\n\n"
           . $this->saludo($t) . "\n\n"
           . "FORMATO DE SALIDA\n"
           . "- Texto plano, sin Markdown ni HTML. Entrega solo el texto del borrador para el cliente.\n"
           . "- Si el cliente indica su nombre y procede, puedes usarlo en el saludo.\n"
           . $this->reglas() . "\n\n";

        [$codigos, $nFotos] = $t->codigos ?? [[], 0];
        $leido = $codigos
            ? "\nCÓDIGO(S) DE BARRAS LEÍDO(S) DE LAS FOTOS DEL CLIENTE (lectura automática fiable): " . implode(', ', $codigos) . ". Úsalo(s) para identificar la etiqueta y menciónalo(s) cuando ayude; no pidas de nuevo el código.\n"
            : ($nFotos ? "\nEL CLIENTE HA ADJUNTADO " . $nFotos . " FOTO(S) pero NO se ha podido leer ningún código de barras en ellas. Si el código hace falta, pide una foto más cercana y frontal, con el código completo y enfocado, sin reflejos, o que lo escriba; no te inventes ningún código.\n" : '');
        $s .= $leido . ($t->hotel ?? '') . ($t->historial ?? '') . ($t->decision ?? '');
        $s .= 'TICKET: ' . ($t->subject ?? '') . ($t->category_name ? ' (categoría: ' . $t->category_name . ')' : '') . ' · canal ' . ($t->channel ?? '') . "\n";
        if (! empty($t->contact_name)) {
            $s .= 'CLIENTE: ' . $t->contact_name . "\n";
        }

        $s .= $this->mapaApp();
        $s .= "\nBASE DE CONOCIMIENTO (preguntas frecuentes publicadas)\n";
        $s .= $faqs ? implode("\n\n", array_map(fn ($f) => '• ' . $f['question'] . "\n" . $f['answer'], $faqs)) : '(ninguna coincide con esta consulta)';

        // Las correcciones parecidas (indexadas) van aparte y primero; las que ya salen en «errores anteriores» no se repiten.
        $correcciones = array_values(array_filter($casos, fn ($d) => $d->source_type === 'correccion' && ! in_array((int) $d->source_id, array_column($ejemplos, 'id'), true)));
        $plantillas = array_values(array_filter($casos, fn ($d) => $d->source_type === 'plantilla'));
        $casos = array_values(array_filter($casos, fn ($d) => ! in_array($d->source_type, ['correccion', 'plantilla'], true)));
        if ($plantillas) {
            $s .= "\n\nPLANTILLAS DE RESPUESTA DEL EQUIPO (textos ya redactados que usa el equipo)\n"
                . "Si una encaja con lo que pide el cliente, úsala como base casi literal: cambia solo lo necesario (de usted, el idioma del cliente, lo que ya sabes de este caso). "
                . "Si ninguna encaja, ignóralas y redacta tú. No cambian el ORDEN DE DECISIÓN: si el orden manda derivar, deriva aunque haya una plantilla; "
                . "no des por hecho nada que la plantilla afirme como ya hecho y no copies correos, direcciones ni plazos que las reglas prohíben.\n";
            foreach ($plantillas as $d) {
                $s .= '[Plantilla: ' . $d->title . "]\n" . mb_substr((string) $d->content, 0, 1200) . "\n\n";
            }
        }
        if ($correcciones) {
            $s .= "\n\nCORRECCIONES DEL EQUIPO A CONSULTAS PARECIDAS (son indicaciones del equipo sobre cómo responder; aplícalas como criterio y redacta con tus palabras, adaptado a este cliente)\n";
            foreach ($correcciones as $d) {
                $s .= mb_substr((string) $d->content, 0, 1800) . "\n\n";
            }
        }

        $guias = array_values(array_filter($casos, fn ($d) => str_contains((string) $d->source_id, '#')));
        $previos = array_values(array_filter($casos, fn ($d) => ! str_contains((string) $d->source_id, '#')));
        if ($guias) {
            $s .= "\n\nGUÍA OFICIAL DE LA APLICACIÓN Y MANUALES (fuente fiable)\n"
                . "Si la consulta se resuelve con estos pasos, RESPONDE con ellos, adaptados a este cliente y con tus palabras: eso es contestar con fiabilidad, no hace falta derivar. "
                . "Nombra la pantalla y el botón tal como aparecen en la guía. Si la guía no cubre lo que pregunta, no completes con suposiciones.\n";
            foreach ($guias as $d) {
                $s .= '[Guía: ' . $d->title . "]\n" . mb_substr((string) $d->content, 0, 1500) . "\n\n";
            }
        }
        if ($previos) {
            $s .= "\n\nCASOS ANTERIORES RESUELTOS (úsalos solo si encajan con esta consulta: de ahí sacas el tono del equipo; no copies datos de otros clientes ni mezcles casos distintos. OJO: muchos son de antes de que los hoteles pasaran a la aplicación nueva, cuando solo el equipo podía hacer posiciones, nombres de etiquetas, usuarios o forzar menús; hoy lo hace el propio hotel, así que si un caso antiguo muestra al equipo haciéndolo por el cliente, NO lo imites: el criterio lo dan la guía oficial y las reglas de arriba)\n";
            foreach ($previos as $d) {
                $aviso = (int) ($d->desfasado ?? 0) === 1
                    ? ' · ANTIGUO: el equipo hizo por el cliente algo que hoy hace el propio hotel; sirve para entender el problema, NO copies sus pasos'
                    : '';
                $s .= '[Caso resuelto: ' . $d->title . $aviso . "]\n" . mb_substr((string) $d->content, 0, 1400) . "\n\n";
            }
        }

        if ($ejemplos) {
            $s .= "\n\nERRORES ANTERIORES (el equipo corrigió estas respuestas; aprende de ellos)\n";
            foreach ($ejemplos as $e) {
                $s .= "- Pregunta del cliente: " . mb_substr($e['pregunta'], 0, 400) . "\n  Respuesta incorrecta: " . mb_substr($e['mala'], 0, 400) . "\n  Indicación del equipo (criterio a aplicar, no texto a copiar): " . mb_substr($e['correccion'], 0, 600) . "\n";
            }
        }
        return $s;
    }

    // -- Utilidades de texto ------------------------------------------------------

    /** HTML de un correo → texto plano acotado (para el prompt). */
    private function plano(string $html): string
    {
        $h = preg_replace('#<(br|/p|/div|/li)\s*/?>#i', "\n", $html);
        $h = html_entity_decode(strip_tags((string) $h), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($this->anonimizar) {
            $h = Scrub::tapar((string) $h, $this->nombreCliente);
        }
        $h = preg_replace("/[ \t]+/", ' ', (string) $h);
        $h = trim((string) preg_replace("/\n{3,}/", "\n\n", (string) $h));
        return mb_substr($h, 0, 2000);
    }

    /** Palabras significativas (≥4 letras, sin tildes, sin las más comunes) para comparar. */
    private function palabras(string $texto): array
    {
        $t = mb_strtolower(preg_replace('/\p{Mn}/u', '', \Normalizer::normalize($texto, \Normalizer::FORM_D) ?: $texto));
        preg_match_all('/[a-z0-9ñ]{4,}/u', $t, $m);
        $vacias = ['para', 'como', 'esta', 'este', 'esto', 'pero', 'porque', 'cuando', 'donde', 'tengo', 'puedo', 'hola', 'buenos', 'buenas', 'gracias', 'saludos', 'favor', 'quiero', 'necesito', 'tiene', 'tienen', 'desde', 'sobre', 'entre', 'unos', 'unas'];
        return array_values(array_unique(array_diff($m[0], $vacias)));
    }
}
