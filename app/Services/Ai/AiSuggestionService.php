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
    /** Lo que hizo la IA en esta sugerencia, paso a paso (se guarda en `sources.traza` y se enseña al agente en el panel). */
    private array $traza = [];
    /** A qué categoría de la app (soporte, garantías, pedidos) debe llevarlo una persona, si la IA derivó. Nota interna para el agente. */
    private ?array $derivarA = null;
    private const CATEGORIAS_DERIVAR = ['soporte' => 'Soporte', 'garantias' => 'Garantías', 'pedidos' => 'Pedidos y facturas'];

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
        ];
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
            foreach (['ai_suggestions', 'ai_lab_messages'] as $tabla) {
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
    public function forTicket(int $ticketId, ?int $userId = null, bool $regenerate = false): ?object
    {
        $ult = DB::table('messages')->where('ticket_id', $ticketId)->where('direction', 'in')
            ->where('is_internal_note', 0)->orderByDesc('id')->first(['id', 'body']);
        if (! $ult) {
            return null;
        }

        if (! $regenerate) {
            $ya = DB::table('ai_suggestions')->where('ticket_id', $ticketId)->where('trigger_message_id', $ult->id)
                ->where('status', '!=', 'failed')->orderByDesc('id')->first();
            if ($ya) {
                return $ya;
            }
        }

        return $this->generate($ticketId, (int) $ult->id, $userId);
    }

    private function generate(int $ticketId, int $messageId, ?int $userId, string $modo = 'suggest', ?int $hasta = null): object
    {
        $fila = $this->redactar($ticketId, $this->ai, false, $hasta) + [
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
                ->first(['t.id', 't.subject', 't.category_id', 't.channel', 'c.name as contact_name', 'c.email as contact_email', 'cat.name as category_name']);
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
        $t->hotel = ($anonimizar && ! $this->evalApi) ? '' : $this->contextoHotel($t->contact_email ?? null, array_values(array_unique(array_merge($t->codigos[0] ?? [], $this->codigosEnTexto($pregunta)))), $textoCliente);

        $this->paso('memoria', null, 'Memoria consultada: ' . count($faqs) . ' preguntas frecuentes · ' . count($ejemplos) . ' respuestas de ejemplo · ' . count($casos) . ' casos/manuales parecidos'
            . ($casos ? ' (' . implode('; ', array_map(fn ($d) => mb_substr((string) ($d->title ?? ''), 0, 60), array_slice($casos, 0, 4))) . ')' : ''),
            ['faqs' => count($faqs), 'ejemplos' => count($ejemplos), 'casos' => array_map(fn ($d) => ['tipo' => (string) ($d->source_type ?? ''), 'titulo' => mb_substr((string) ($d->title ?? ''), 0, 90)], array_slice($casos, 0, 6))]);
        $fuentes = fn () => json_encode(['faqs' => array_column($faqs, 'id'), 'examples' => array_column($ejemplos, 'id'), 'docs' => array_map(fn ($d) => (int) $d->id, $casos), 'traza' => $this->traza, 'derivar' => $this->derivarA]);
        $fila = ['sources' => $fuentes()];
        $this->avisar('Redactando la respuesta…');
        try {
            $r = $ai->complete($this->instrucciones($t, $faqs, $ejemplos, $casos), $this->comoTurnos($conversacion));
            if (! empty($r['truncated'])) {
                // Un texto cortado a medias no se puede enseñar como si estuviera completo.
                $fila += ['model' => $r['model'], 'input_tokens' => $r['input_tokens'], 'output_tokens' => $r['output_tokens']];   // se pagó aunque no sirva
                throw new AiException('La respuesta de la IA salió incompleta');
            }
            [$texto, $this->derivarA] = self::sacarDerivacion(trim($r['text']));
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
            $k = str_starts_with($k, 'pedido') ? 'pedidos' : (str_starts_with($k, 'garant') ? 'garantias' : $k);
            if (isset(self::CATEGORIAS_DERIVAR[$k]) && $dest === null) {
                $dest = ['categoria' => self::CATEGORIAS_DERIVAR[$k], 'motivo' => mb_substr(trim($m[2] ?? ''), 0, 160)];
            }
            return "\n";
        }, $texto);
        return [trim((string) $limpio), $dest];
    }

    /** Clasificación aproximada de lo que hace el borrador, solo para el panel del agente. */
    private static function tipoDeRespuesta(string $t): string
    {
        if (preg_match('/compañer[oa]s? (del equipo )?(revisar|le atender|comprobar|se pondr)|equipo (de [\wáéíóú\/ ]+ )?(revisar|le atender)/iu', $t)) {
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
    public function evaluarCaso(string $asunto, string $pregunta, ?int $excluirTicket, AiProvider $ai, bool $conApi = false): array
    {
        $this->excluirTicket = $excluirTicket;
        $this->evalApi = $conApi;   // casos [API]: la IA también ve lo que AdminHoteles diga del hotel que nombra el mensaje (solo lectura)
        try {
            $t = (object) ['id' => null, 'subject' => $asunto, 'category_id' => null, 'channel' => 'chat', 'category_name' => null,
                'contact_name' => null, 'contact_email' => null, 'yaRespondio' => false];
            return $this->componer($t, [['role' => 'user', 'text' => $pregunta]], null, $ai, true);
        } finally {
            $this->excluirTicket = null;
            $this->evalApi = false;
        }
    }

    /**
     * Valora una sugerencia. «down» exige la corrección: sin ella no hay nada de lo que
     * aprender, y es justo lo que se guarda para alimentar a la IA.
     */
    public function rate(int $id, string $rating, ?string $correction, int $userId): object
    {
        if (! in_array($rating, ['up', 'down'], true)) {
            throw new InvalidArgumentException('Valoración no válida');
        }
        $correction = trim((string) $correction);
        if ($rating === 'down' && mb_strlen($correction) < 5) {
            throw new InvalidArgumentException('Escribe qué debía responder (mínimo 5 letras) para poder aprender de ello');
        }

        // Una corrección nueva o editada queda PENDIENTE: no llega a la IA hasta que la valide un
        // responsable. Si quien corrige ya es responsable (support.config), cuenta como validada.
        $responsable = $rating === 'down' && (bool) \App\Models\User::find($userId)?->can('support.config');
        DB::table('ai_suggestions')->where('id', $id)->update([
            'rating' => $rating, 'correction' => $rating === 'down' ? mb_substr($correction, 0, 4000) : null,
            'rated_by' => $userId, 'rated_at' => now(), 'updated_at' => now(),
            'correction_status' => $responsable ? 'validated' : 'pending',
            'correction_reviewed_by' => $responsable ? $userId : null,
            'correction_reviewed_at' => $responsable ? now() : null,
        ]);
        // Solo si está validada pasa a ser conocimiento buscable (y deja de serlo si cambia a 👍).
        $this->knowledge->indexarCorreccion($id);
        return DB::table('ai_suggestions')->where('id', $id)->first();
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
        return $n > 0;
    }

    public function markUsed(int $id): void
    {
        DB::table('ai_suggestions')->where('id', $id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);
    }

    // -- Conocimiento -------------------------------------------------------------

    /** Las últimas mensajes visibles al cliente (sin notas internas), de antiguo a nuevo. */
    private function conversacion(int $ticketId): array
    {
        $rows = DB::table('messages')->where('ticket_id', $ticketId)->where('is_internal_note', 0)
            ->when($this->hasta, fn ($q) => $q->where('id', '<=', $this->hasta))
            ->whereNotNull('body')->orderByDesc('id')->limit(self::MAX_MENSAJES)->get(['direction', 'body', 'type', 'media_mime']);

        return $rows->reverse()->map(function ($m) {
            $texto = $this->plano((string) $m->body);
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
    private function contextoHotel(?string $contactEmail, array $codigos = [], string $textoCliente = ''): string
    {
        $this->hotelAmbiguo = null;
        $s = $this->contextoHotelBase($contactEmail, $codigos, $textoCliente);
        if ($s !== '' && $this->hotelAmbiguo !== null) {
            // El nombre encaja con varios hoteles (p. ej. «Hotel Poseidón» hay más de uno): nunca se asume cuál es.
            // No se enumeran los hoteles al cliente: solo se le pide el nombre completo.
            $s .= "- AMBIGUO: el nombre «" . $this->hotelAmbiguo . "» corresponde a VARIOS hoteles. NO asumas cuál es y NO des datos de ninguno: "
                . "pregúntale simplemente el nombre completo del hotel antes de seguir. NO le digas que hay varios hoteles con ese nombre ni nombres otros: "
                . "quien escribe podría no ser del hotel y no debe saber qué hoteles existen.\n";
        }
        return $s;
    }

    private function contextoHotelBase(?string $contactEmail, array $codigos = [], string $textoCliente = ''): string
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
                $r = array_values(array_filter($r, fn ($h) => $nn !== '' && (str_contains(HotelsApi::norm($h['nombre']), $nn) || str_contains($nn, HotelsApi::norm($h['nombre'])))));
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
            $this->paso('hotel', true, 'Hotel localizado: ' . $dir[0]['nombre'] . (! empty($dir[0]['grupo']) ? ' (' . $dir[0]['grupo'] . ')' : '') . (count($dir) > 1 ? ' · +' . (count($dir) - 1) . ' coincidencias' : ''), ['nombre' => $dir[0]['nombre'], 'grupo' => $dir[0]['grupo'] ?? null, 'otras' => count($dir) - 1]);
        } elseif ($nombres) {
            $this->paso('hotel', false, 'Hotel no localizado: «' . $nombres[0] . '»' . ($dirFalla ? ' (el panel no respondió)' : ''));
        } else {
            $this->paso('hotel', null, 'El cliente no nombra ningún hotel');
        }
        $this->avisar('Comprobando el correo en AdminHoteles…');
        $v = $api->verificar($e);
        $sitios = array_slice($v['sitios'], 0, 4);
        // Traza para el agente: hotel y correo (lo que ve el panel de la sugerencia).
        if ($v['estado'] === 'sin_correo') {
            $this->paso('correo', null, 'Sin correo que comprobar');
        } elseif ($v['estado'] === 'incompleto') {
            $this->paso('correo', null, "No se pudo comprobar el correo $e ahora (el panel no respondió)");
        } elseif ($v['estado'] === 'no_encontrado') {
            $this->paso('correo', false, "El correo $e no figura como usuario de la aplicación");
        } elseif ($dir && ! $this->coincideHotel($dir, $sitios)) {
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
                return $cab . "- Aún no sabemos de qué hotel es ni con qué correo accede. Pregunta PRIMERO el nombre del hotel y, a continuación, el correo con el que accede a la aplicación.\n" . $comun;
            }
            if ($dir) {
                if (! $pedido && ($antenas = $this->antenasSinVerificar($api, $dir, $nombres, $unicos))) {
                    return $cab . $antenas;
                }
                return $cab . "- El cliente dice ser de «" . $dir[0]['nombre'] . "». Falta comprobar su correo.\n"
                    . "- VERIFICAR PRIMERO, EXPLICAR DESPUÉS: si lo que pide implica CONFIGURAR algo de su buffet en la aplicación (crear o editar posiciones, isletas, etiquetas, servicios, ruedas…), NO expliques todavía los pasos: pídele el correo con el que accede a la aplicación (el hotel ya lo tienes) y dile que, en cuanto lo confirme, le indicas cómo hacerlo; una petición simple de explicación de cómo se usa o se accede a la aplicación (entrar desde el móvil, qué hace una pantalla) sí se explica directamente. Pide el correo siempre que necesites datos concretos de ese hotel (usuarios, permisos, etiquetas concretas).\n" . $comun;
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
        if ($v['estado'] === 'no_encontrado') {
            return $cab . "- El correo del cliente ($e) NO figura como usuario en la aplicación de hoteles.\n"
                . "- Explícaselo con tacto: con ese correo no podemos asociarle a un hotel en nuestro sistema.\n" . $pedir . $tacto . $comun . $derivar;
        }
        // encontrado: ¿coincide con el hotel que dice ser?
        if ($dir && ! $this->coincideHotel($dir, $sitios)) {
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
                . ".\n- USUARIOS Y PERMISOS: si pide acceso o permisos (crear platos, modificar menús, forzar, etc.), dile qué rol tiene hoy y, con la documentación de roles, qué permite ese tipo de rol (sin inventar permisos que no sepas). Si el rol parece suficiente, indícale dónde se hace en la aplicación. Si necesita más permisos, o que se cree o reactive un usuario, lo hace una persona: DERIVA, pero la respuesta DEBE incluir antes una frase con su rol actual («Su usuario tiene hoy el rol X») (esto prevalece sobre «sé breve» al derivar), y termina con que un compañero revisará la ampliación de permisos. NUNCA afirmes qué permisos incluye o no incluye su rol concreto: los roles los personaliza cada hotel y tú solo sabes el NOMBRE del rol. No digas que ya está hecho y no facilites usuarios ni contraseñas.\n";
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
        return array_slice(array_keys($out), 0, 4);
    }

    /** ¿Alguno de los hoteles buscados es uno de los sitios donde figura el correo? (nombres sin tildes, uno dentro de otro) */
    private function coincideHotel(array $dir, array $sitios): bool
    {
        foreach ($dir as $d) {
            $a = HotelsApi::norm($d['nombre']);
            foreach ($sitios as $x) {
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
                . "- El equipo ya contestó antes en esta conversación: NO saludes, NO te presentes ni repitas «le hablo de AEME Group». Entra directamente en la respuesta.\n"
                . "- Tampoco repitas la firma ni la despedida larga; basta una frase breve de cierre si procede.";
        }
        $h = (int) now()->format('G');
        $f = $h >= 6 && $h < 14 ? 'Buenos días' : ($h < 21 && $h >= 14 ? 'Buenas tardes' : 'Buenas noches');
        return "SALUDO Y PRESENTACIÓN (anula lo que digan las instrucciones anteriores)\n"
            . "- Es el primer mensaje del equipo en esta conversación. Ahora mismo toca «{$f}»: empieza con «{$f}, le hablo de AEME Group.» (en el idioma del cliente, manteniendo el saludo adecuado a la hora).";
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
           . "REGLA DE DERIVACIÓN A UN HUMANO (prevalece sobre todo lo demás)\n"
           . "- No sabes todo y no tienes que contestar siempre. Si no estás seguro, si la consulta no encaja con el contexto de abajo, si la respuesta exigiría suponer algo, o si hay riesgo de dar una instrucción incorrecta: NO respondas ni improvises; responde únicamente con " . self::NO_SABE . " y una persona del equipo se hará cargo.\n"
           . "- Lo mismo en la gestión de garantías (salvo el protocolo de reinicio de etiquetas, que sí se explica), precios o condiciones comerciales, acceso remoto (AnyDesk) y cualquier cambio interno no documentado: responde solo con " . self::NO_SABE . "\n"
           . "- Es preferible derivar que dar una respuesta dudosa. Nunca inventes procedimientos, datos, estados ni plazos. Solo puedes pedir datos al cliente cuando el contexto indique claramente cuáles hacen falta.\n"
           . "- PRIORIDAD: las reglas de «SIEMPRE DERIVA» (más abajo) van ANTES que pedir el hotel o el correo. Si el mensaje cae en uno de esos temas, NO preguntes nada: agradece, di que un compañero del equipo lo revisará y le atenderá personalmente, y termina.\n"
           . "- ACTIVAR UNA ETIQUETA EN UNA POSICIÓN (manda sobre las demás reglas de derivación y sobre los tickets parecidos, donde el equipo lo hacía por el cliente): si el cliente pide «activar» una etiqueta en una posición concreta (por ejemplo «la activación de esta etiqueta en la posición SC-06»), quiere crear o editar esa posición y asignarle la etiqueta, y eso lo hace el propio hotel desde Posiciones. NO derives ni respondas con '. self::NO_SABE .': si el correo no está verificado, pide el nombre completo del hotel y el correo con el que accede; con el correo verificado, explica los pasos. Aunque diga «esta etiqueta» sin que se vea cuál (foto o código que no llegan), la petición no está incompleta: la posición ya la dice. QUE FALTE EL CORREO, EL HOTEL COMPLETO, EL CÓDIGO O LA FOTO NO ES MOTIVO PARA DERIVAR: es motivo para PEDIRLOS (hotel completo y correo; el código de la etiqueta si lo necesitas). Solo el ALTA de etiquetas NUEVAS en la plataforma (registrarlas por primera vez, sin hablar de posiciones) la hace el equipo: eso sí se deriva.\n"
           . "- PREGUNTAS AL CLIENTE: como máximo DOS por mensaje, nunca una lista de tres o más. Si aún no se sabe quién escribe, pregunta SOLO el nombre del hotel (o sede) y el correo con el que accede a la aplicación; el resto de detalles se pide después, cuando conteste. Si ya lo sabes, no lo repitas: pregunta lo más importante, como mucho dos cosas.\n"
           . "- NO PREGUNTES LO OBVIO NI LO INVENTADO: solo preguntas algo si su respuesta cambia lo que hay que hacer y el dato no está ya en el mensaje. No pidas confirmar lo que el cliente ya dijo con claridad (fechas, año, hotel, qué quiere) ni sondees detalles que no aparecen en tu contexto o en la documentación (por ejemplo, «si afecta a todos los servicios» o «si las fechas son de este año»). Si el cliente ya dio lo necesario y la gestión la hace una persona del equipo, no preguntes más: deriva.\n"
           . "- PROBLEMA CONCRETO SIN HOTEL: si el cliente cuenta un problema real de uso (etiquetas que no cambian o no actualizan, error al emparejar o vincular, error de permisos como «unauthorized», «verifica mi usuario» o «no puedo entrar») y el mensaje NO nombra ningún hotel ni lo firma, NO derives: pide el nombre del hotel y el correo con el que accede a la aplicación (máximo esas dos preguntas). Derivar viene después, ya con el hotel conocido. EXCEPCIONES, aquí se deriva sin preguntar: (1) si lo que falla es el CONTENIDO que muestran la aplicación o las etiquetas (la palabra «null» o «array», un plato que no toca, datos raros): es un fallo de código; (2) si el cliente SÍ nombra un hotel y el problema es concreto (dice qué etiqueta o qué ocurre), aunque no lo localices; PERO si nombra el hotel y el problema es VAGUO («algún letrero da problemas», «tengo un problema con una etiqueta», sin decir cuál ni qué le pasa; un fallo GENERAL como «las etiquetas no funcionan», «¿está caído el sistema?» o «el menú no cambia» NO es vago: ahí aplica lo de las antenas), NO derives: pídele como máximo dos cosas, qué etiqueta o posición es (código si lo tiene) y qué muestra o qué falla; (3) si el mensaje cae en un tema de la lista siguiente y además trae ya el hotel.\n"
           . "- SIEMPRE DERIVA a una persona, sin preguntar más y sin explicar procedimientos: alérgenos, la gestión de garantías y material (sustituir, enviar o recibir etiquetas nuevas, recambios; EXCEPCIÓN: si las etiquetas fallan o se quedan en blanco y las antenas están en línea, antes se explica el protocolo de reinicio de la etiqueta de la documentación, y solo se deriva si el cliente dice que ya lo hizo y sigue igual), presupuestos y envíos, mensajes incompletos o sin NINGUNA petición ni problema concreto (por ejemplo, un adjunto que no ves; si hay un problema concreto sin hotel, aplica la regla de PROBLEMA CONCRETO SIN HOTEL), cualquier petición de que te «habiliten» o activen PERMISOS o la configuración de la cuenta (ojo: «activar la etiqueta X en la posición Y» NO es esto: ver la regla ACTIVAR UNA ETIQUETA EN UNA POSICIÓN), preguntas sobre cómo mejorar la conexión o cobertura (no inventes procedimientos), pantallas LCD, televisores o carruseles de pantalla (no son etiquetas: aunque el cliente cuente que no actualizan, no des pasos de antenas ni de forzar), datos de PRECIO que muestran las etiquetas (precio por kilo o litro, ofertas: son tiendas y se trabajan aparte), tótems, formaciones o reuniones, dominios y hosting (CDMON), fallos de la aplicación que pueden ser de código (incluida una rueda de menú o cartelería que no cambia en las horas programadas CUANDO el cliente ya dice que tiene que forzar cada servicio a mano o que ya forzó y sigue igual; si todavía no ha forzado nada, explícale cómo se fuerza el menú), y clientes que no son hotel, tienda ni farmacia. Quien pide que el equipo ejecute un cambio (cambiar la rueda de menú de un buffet, logos, plantillas, altas o permisos de usuario) también se deriva; nunca facilites usuarios ni contraseñas. Lo que el hotel hace por sí mismo en la aplicación (crear posiciones o isletas, asignar etiquetas ya dadas de alta a una posición, girarlas, activar servicios, y OJO: si el cliente pide «activar esta etiqueta en la posición X» quiere crear/editar esa posición, lo hace él (pide hotel y correo y explica); solo el ALTA de etiquetas nuevas en la plataforma la hace el equipo; y si dice «esta etiqueta» sin que se vea cuál, la petición NO está incompleta (la posición ya la dice): no derives por incompleta, pide hotel y correo, y también MOVER, INTERCAMBIAR o reasignar posiciones o platos entre etiquetas, o modificar lo que muestra un cartel o un listado de etiquetas a cambiar: aunque lo pidan como «¿podéis modificar este cartel?» o te pasen un listado, no se deriva sin más) se le explica cómo hacerlo con la documentación, sin confirmar nunca que ya está hecho, AUNQUE el cliente lo pida como «¿podéis agregar/crear estas etiquetas o posiciones?» (el cliente no sabe que puede hacerlo él: explícale los pasos, p. ej. Posiciones > Crear nueva posición, isleta, etiqueta). Solo las altas de USUARIOS y los permisos los hace el equipo y se derivan.\n"
           . "- EXPLICAR O VERIFICAR PRIMERO: cómo se ACCEDE o se usa la aplicación (entrar desde el móvil, qué hace una pantalla) se explica directamente. Pero si la petición CONFIGURA algo del buffet (crear o editar posiciones, isletas, etiquetas, servicios) o toca datos o configuración CONCRETOS de ese hotel, y todavía no está verificado el correo, primero se pide el correo y se explica después (ejemplos: desbloquear o bloquear servicios, reactivar etiquetas inactivas, crear una isleta, cambiar algo en su cuenta). Si todavía no sabes quién escribe, NO expliques el procedimiento: pide primero el nombre del establecimiento y el correo con el que accede (máximo esas dos preguntas) y el resto se atiende después. Y si el cliente cuenta que YA lo intentó y la aplicación no se lo permite («no nos deja habilitarlas», «sale error»), no le repitas los pasos genéricos: deriva con la frase corta.\n"
           . "- SI HAY PROCEDIMIENTO EN EL CONTEXTO, EXPLÍCALO: una duda de cómo se hace algo en la aplicación (duplicar o clonar ruedas de menú o servicios, cambiar el horario de un servicio, ASIGNAR etiquetas a posiciones, forzar el menú cuando las etiquetas no se actualizan y las antenas están en línea) NO se contesta con " . self::NO_SABE . " ni se deriva mientras la documentación de abajo traiga los pasos: explícaselos (si falta verificar el correo, pídelo antes). Pedir datos solo si de verdad falta algo que cambie los pasos.\n"
           . "- MENÚ QUE CAMBIA A UNA HORA EQUIVOCADA: si el cliente dice que el menú o las etiquetas cambian demasiado pronto o tarde («a las 21:00 ya tenemos el desayuno», «el horario del servicio está mal»), NO es un fallo de código ni se deriva: es el horario o la hora de ejecución automática del servicio, que el hotel edita desde Servicios. Explícale cómo (documentación de servicios). Si todavía no está verificado el correo, pídelo antes de explicar.\n"
           . "- ASIGNAR, MOVER O CAMBIAR POSICIONES SIN CORREO VERIFICADO: si piden asignar etiquetas a posiciones, moverlas o cambiarlas (con o sin listado de códigos, aunque lo escriban como «¿podéis asignar…?» o «cuando pueda asignar las posiciones») y todavía no está verificado el correo, NO derives ni digas que un compañero lo revisará: pide el correo con el que accede a la aplicación (y el hotel si falta). Con el correo verificado se explica cómo hacerlo desde Posiciones.\n"
           . "- PEDIR EL CORREO PARA ACCESOS: si el cliente no puede entrar, no puede restablecer su contraseña o pide acceso a un usuario, y no sabes con qué correo accede, pídele ese correo (y el hotel si falta): no derives sin más.\n"
           . "- CONSULTA ANUNCIADA SIN CONTENIDO: un mensaje como «quería hacer una consulta» sin decir cuál NO se deriva: pregunta con amabilidad cuál es su consulta y de qué hotel o establecimiento escribe.\n"
           . "- NOTA INTERNA AL DERIVAR: quien lee tu borrador es una persona del equipo de soporte, que no puede derivar a «un compañero» sin saber a quién. Cuando derives (con la frase corta o con " . self::NO_SABE . "), añade AL FINAL, en una línea aparte, «[[DERIVAR:categoria|motivo en pocas palabras]]». categoria es una de: soporte (uso de la aplicación, fallos, usuarios, permisos, cambios que hace el equipo), garantias (etiquetas que fallan, pilas, sustitución, recambios), pedidos (material, presupuestos, envíos, facturas). Esa línea no la ve el cliente. Si no derivas, no la añadas.\n"
           . "- AL DERIVAR sé breve: «Gracias por su mensaje. Hemos registrado la incidencia y el equipo la revisará.» No repitas ni resumas lo que cuenta el cliente, no enumeres sus incidencias ni añadas explicaciones.\n"
           . "- NUNCA le digas al cliente que existen varios hoteles con un mismo nombre, ni cuántos hay, ni nombres otros: quien escribe podría no ser del hotel. Si dudas de cuál habla, pregunta solo «¿Me indica el nombre completo del hotel?».\n"
           . "- AUDIOS: «" . self::MARCA_AUDIO . "» en la conversación es un audio de WhatsApp que no podemos escuchar. No supongas lo que decía. Si además hay texto del cliente, contesta solo al texto; si el texto remite al audio («como te dije en el audio»), pídele con tacto que lo escriba.\n"
           . "- Usa solo la información de este ticket y de las secciones de contexto siguientes; el contexto de otros casos son patrones para orientarte, nunca datos para citar.\n\n";

        [$codigos, $nFotos] = $t->codigos ?? [[], 0];
        $leido = $codigos
            ? "\nCÓDIGO(S) DE BARRAS LEÍDO(S) DE LAS FOTOS DEL CLIENTE (lectura automática fiable): " . implode(', ', $codigos) . ". Úsalo(s) para identificar la etiqueta y menciónalo(s) cuando ayude; no pidas de nuevo el código.\n"
            : ($nFotos ? "\nEL CLIENTE HA ADJUNTADO " . $nFotos . " FOTO(S) pero NO se ha podido leer ningún código de barras en ellas. Si el código hace falta, pide una foto más cercana y frontal, con el código completo y enfocado, sin reflejos, o que lo escriba; no te inventes ningún código.\n" : '');
        $s .= $leido . ($t->hotel ?? '');
        $s .= 'TICKET: ' . ($t->subject ?? '') . ($t->category_name ? ' (categoría: ' . $t->category_name . ')' : '') . ' · canal ' . ($t->channel ?? '') . "\n";
        if (! empty($t->contact_name)) {
            $s .= 'CLIENTE: ' . $t->contact_name . "\n";
        }

        $s .= "\nBASE DE CONOCIMIENTO (preguntas frecuentes publicadas)\n";
        $s .= $faqs ? implode("\n\n", array_map(fn ($f) => '• ' . $f['question'] . "\n" . $f['answer'], $faqs)) : '(ninguna coincide con esta consulta)';

        // Las correcciones parecidas (indexadas) van aparte y primero; las que ya salen en «errores anteriores» no se repiten.
        $correcciones = array_values(array_filter($casos, fn ($d) => $d->source_type === 'correccion' && ! in_array((int) $d->source_id, array_column($ejemplos, 'id'), true)));
        $casos = array_values(array_filter($casos, fn ($d) => $d->source_type !== 'correccion'));
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
            $s .= "\n\nCASOS ANTERIORES RESUELTOS (úsalos solo si encajan con esta consulta: de ahí sacas el criterio y el tono del equipo; no copies datos de otros clientes ni mezcles casos distintos)\n";
            foreach ($previos as $d) {
                $s .= '[Caso resuelto: ' . $d->title . "]\n" . mb_substr((string) $d->content, 0, 1400) . "\n\n";
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
