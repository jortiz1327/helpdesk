<?php

namespace App\Console\Commands;

use App\Services\Ai\AiProviders;
use Illuminate\Console\Command;

/**
 * Propone decisión y comentario para los casos del conjunto de pruebas que el revisor aún no ha mirado, imitando los
 * que SÍ ha revisado. Solo escribe propuestas.json: NO toca marcas.json, así que nada cuenta como revisado hasta que
 * la persona acepte o corrija cada propuesta en /ia/casos.
 *
 * ¡Gasta tokens del proveedor activo (una llamada por caso)!
 */
class AiPrerrellenar extends Command
{
    protected $signature = 'ai:prerrellenar {--limite= : Máximo de casos (para probar)} {--rehacer : Vuelve a proponer también los que ya tienen propuesta}';
    protected $description = 'Propone decisión y comentario para los casos sin revisar, imitando los ya revisados (gasta tokens)';

    private const CRITERIOS = <<<'TXT'
Criterios del revisor (aplícalos):
- Alérgenos: SIEMPRE derivar, sin preguntar nada más.
- Como mucho DOS preguntas por mensaje; si no se sabe quién escribe, solo hotel y correo.
- No inventar preguntas: solo se pregunta lo que cambia lo que hay que hacer y no está ya en el mensaje (nunca «si afecta a todos los servicios» ni «si las fechas son de este año»).
- Si el cliente ya nombra el hotel, se busca en AdminHoteles con ese nombre; solo se pide el nombre exacto si no aparece. Cuando el caso depende de consultar AdminHoteles (hotel, usuario, buffet, antenas, ruedas de menú), el comentario empieza por [API].
- «Pasar de buffet normal a reducido» es cambiar de rueda de menú del mismo buffet.
- Si lo que pide el cliente lo ejecuta una persona del equipo (cambios en buffets, permisos, altas/bajas), la IA no puede confirmar que está hecho: deriva.
- Garantías, precios, material/pilas y acceso remoto: derivar.
TXT;

    private static function limpia(string $t, int $max): string
    {
        return mb_substr(trim(preg_replace('/\s+/u', ' ', str_replace("\r", '', $t))), 0, $max);
    }

    public function handle(): int
    {
        $dir = (string) config('ai.eval_dir');
        if (! is_file("$dir/candidatos.json") || ! is_file("$dir/marcas.json")) {
            $this->error('Faltan candidatos.json y marcas.json.');
            return self::FAILURE;
        }
        $ai = AiProviders::activo();
        if (! $ai->configured()) {
            $this->error('El proveedor de IA activo no tiene clave en el .env.');
            return self::FAILURE;
        }
        $cand = json_decode((string) file_get_contents("$dir/candidatos.json"), true);
        $marcas = json_decode((string) file_get_contents("$dir/marcas.json"), true);
        $propFile = "$dir/propuestas.json";
        $props = is_file($propFile) ? (json_decode((string) file_get_contents($propFile), true) ?: []) : [];

        $ejemplos = '';
        $nEj = 0;
        foreach ($cand as $c) {
            $m = $marcas[$c['id']] ?? null;
            if (! $m || empty($m['revisado'])) {
                continue;
            }
            $nEj++;
            $ejemplos .= "--- EJEMPLO $nEj ---\nCliente: " . self::limpia((string) $c['pregunta'], 350)
                . "\nRespondió una persona: " . self::limpia((string) $c['respuesta_humana'], 200)
                . "\nDECISIÓN DEL REVISOR: {$m['decision']}\nCOMENTARIO DEL REVISOR: " . self::limpia((string) ($m['comentario'] ?? ''), 600) . "\n\n";
        }
        if ($nEj === 0) {
            $this->error('No hay casos revisados que sirvan de ejemplo.');
            return self::FAILURE;
        }

        $sistema = "Ayudas a un revisor a etiquetar casos reales de soporte para evaluar a una IA de soporte de AEME Group (etiquetas electrónicas y buffets de hoteles). "
            . "Para cada caso propones qué debería hacer la IA: responder (puede contestar sola), pedir_info (debe pedir datos al cliente) o derivar (lo debe llevar una persona), "
            . "o descartar (el mensaje no sirve como prueba: conversación a medias, agradecimiento, spam).\n\n"
            . self::CRITERIOS . "\n\nEjemplos ya revisados por la persona (imítalos en decisión y en estilo del comentario, breve y concreto):\n\n$ejemplos"
            . "Contesta SOLO con un JSON {\"decision\":\"responder|pedir_info|derivar|descartar\",\"comentario\":\"...\"}. "
            . "El comentario es 1-3 frases: qué debería hacer la IA y por qué. NO inventes procedimientos ni preguntas que no estén en los criterios o en lo que hizo la persona. "
            . "Si dudas de la decisión, elige la más prudente (derivar) y empieza el comentario por «DUDA:».";

        $pend = array_values(array_filter($cand, fn ($c) => empty(($marcas[$c['id']] ?? [])['revisado'])
            && ($this->option('rehacer') || ! isset($props[$c['id']]))));
        if ($this->option('limite') !== null) {
            $pend = array_slice($pend, 0, max(1, (int) $this->option('limite')));
        }
        $this->info(count($pend) . " casos por proponer, con $nEj ejemplos revisados");

        $in = $out = $fallos = 0;
        $bar = $this->output->createProgressBar(count($pend));
        foreach ($pend as $c) {
            $user = "CASO\nAsunto: " . self::limpia((string) $c['asunto'], 150) . "\nCliente: " . self::limpia((string) $c['pregunta'], 1500)
                . "\nLo que respondió una persona: " . self::limpia((string) $c['respuesta_humana'], 600)
                . "\nDecisión sugerida por la extracción automática: " . ($c['decision_propuesta'] ?? '?');
            try {
                $r = $ai->complete($sistema, [['role' => 'user', 'content' => $user]]);
                $in += (int) ($r['input_tokens'] ?? 0);
                $out += (int) ($r['output_tokens'] ?? 0);
                $txt = (string) ($r['text'] ?? '');
                $j = preg_match('/\{.*\}/su', $txt, $mm) ? json_decode($mm[0], true) : null;
                $dec = is_array($j) ? ($j['decision'] ?? null) : null;
                if (! in_array($dec, ['responder', 'pedir_info', 'derivar', 'descartar'], true)) {
                    $fallos++;
                    continue;
                }
                $props[$c['id']] = ['decision' => $dec, 'comentario' => trim((string) ($j['comentario'] ?? ''))];
                file_put_contents($propFile, json_encode($props, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
            } catch (\Throwable $e) {
                $fallos++;
            }
            $bar->advance();
        }
        $bar->finish();
        $this->newLine(2);

        [$pi, $po] = config('ai.prices.' . config('ai.providers.' . config('ai.provider') . '.model')) ?? config('ai.prices.default', [0, 0]);
        $this->line(sprintf('Propuestas guardadas: %d · sin respuesta válida: %d · tokens %s/%s · coste estimado %.3f $',
            count($pend) - $fallos, $fallos, number_format($in), number_format($out), $in / 1e6 * $pi + $out / 1e6 * $po));
        $this->line("Archivo: $propFile (las marcas del revisor NO se han tocado)");
        return self::SUCCESS;
    }
}
