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
    protected $signature = 'ai:prerrellenar {--limite= : Máximo de casos (para probar)} {--lote= : Solo los casos de este lote (nombre completo)} {--rehacer : Vuelve a proponer también los que ya tienen propuesta}';
    protected $description = 'Propone decisión y comentario para los casos sin revisar, imitando los ya revisados (gasta tokens)';

    private const CRITERIOS = <<<'TXT'
Criterios del revisor (aplícalos):
- Alérgenos: lo que afecta a un PLATO concreto (qué lleva, falta o está mal) se deriva sin preguntar. Qué alérgenos y distintivos se muestran (Configuración > Alérgenos: activar o desactivar, «solo salen 17») lo hace el propio hotel: responder explicando los pasos.
- Como mucho DOS preguntas por mensaje; si no se sabe quién escribe, solo hotel y correo.
- No inventar preguntas: solo se pregunta lo que cambia lo que hay que hacer y no está ya en el mensaje (nunca «si afecta a todos los servicios» ni «si las fechas son de este año»).
- Si el cliente ya nombra el hotel, se busca en AdminHoteles con ese nombre; solo se pide el nombre exacto si no aparece. Cuando el caso depende de consultar AdminHoteles (hotel, usuario, buffet, antenas, ruedas de menú), el comentario empieza por [API].
- «Pasar de buffet normal a reducido» es cambiar de rueda de menú del mismo buffet.
- Lo que EJECUTA el equipo y se deriva: cargar o cambiar los PLATOS de la rueda de menú de un buffet, logos, tipos de letra, plantillas y el ALTA de etiquetas nuevas en la plataforma. La IA no puede confirmar que está hecho.
- LO QUE HACE EL PROPIO HOTEL en la aplicación NO se deriva, y el equipo NO lo gestiona: crear o activar SERVICIOS y sus horarios (también uno nuevo como «snacks», con su ventana de apertura y cierre), isletas, posiciones, asignar/codificar/parametrizar/vincular una etiqueta a una posición, idiomas, usuarios, roles y permisos, forzar el menú. Decisión: pedir_info si el correo no está verificado (solo el correo si el hotel ya consta) o responder explicando con la guía. Las respuestas humanas antiguas que lo hacían por el cliente están desfasadas.
- Usuario o contraseña que no funciona, no poder entrar o pedir restablecerla: derivar sin preguntar nada. Crear usuarios nuevos lo hace el hotel.
- Etiqueta(s) suelta(s) que no cambian, se bloquean o se quedan en blanco, sin pedir que se envíe nada (aunque sean dos o tres): primero va el PROTOCOLO DE REINICIO (quitar las pilas 15 minutos, con las antenas en línea). Si el cliente aún no ha dicho hotel y correo, lo primero es pedirlos (pedir_info); con el cliente ya identificado es responder con los pasos del reinicio. Solo se deriva si dice que ya lo hizo y sigue igual.
- Garantías (etiquetas que fallan y hay que sustituir o enviar, un lote grande, o ya probado el reinicio) y acceso remoto: derivar sin preguntar. Nunca se da un correo para garantías. Una farmacia con garantía: la gestiona Expofarm, sin pedir datos.
- PEDIDOS DE MATERIAL (comprar o reponer pilas, soportes, recambios, etiquetas, pinzas): se RESPONDE con «escriba a postventa@aemegroup.com con material, cantidad y foto». Decisión: responder, no derivar. Ignora los ejemplos antiguos que derivaban el material.
- PRESUPUESTOS, dos casos: si pide EL CORREO o dónde pedirlo, responder con el correo de postventa; si pide que NOSOTROS lo preparemos, derivar (el borrador es «Asignar al departamento de Pedidos y facturas.»).
- Farmacias: no se busca en AdminHoteles ni se pide hotel. Si el stock ya no se ve en las etiquetas tras la nueva versión, se responde: activar el stock en Configuración y esperar la actualización de las 23:00.
- Un caso de uso de la aplicación sin hotel ni correo conocidos: pedir_info (hotel y correo), no derivar. Un seguimiento («gracias», «ya funciona») sin petición nueva: descartar.
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
        // Solo los ejemplos revisados más recientes: los antiguos son de cuando las reglas eran otras (y derivaban cosas que hoy hace el hotel).
        $revisados = array_values(array_filter($cand, fn ($c) => ! empty(($marcas[$c['id']] ?? [])['revisado'])));
        foreach (array_slice($revisados, -60) as $c) {
            $m = $marcas[$c['id']] ?? null;
            if (! $m || empty($m['revisado'])) {
                continue;
            }
            $nEj++;
            $ejemplos .= "--- EJEMPLO $nEj ---\nCliente: " . self::limpia((string) $c['pregunta'], 350)
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
            . "IMPORTANTE: que en el ticket real lo ejecutara una persona del equipo NO significa que deba derivarse: muchos tickets son antiguos y hoy lo hace el propio hotel (posiciones, servicios, ruedas, cambios de menú por fecha, usuarios, etiquetas ya dadas de alta, forzar el menú). Si los CRITERIOS dicen que lo hace el hotel, la decisión es responder (o pedir_info si falta hotel o correo), aunque los ejemplos o el ticket digan otra cosa. Deriva solo si los criterios lo dicen. Si dudas entre dos decisiones, empieza el comentario por «DUDA:» y di cuáles.";

        $pend = array_values(array_filter($cand, fn ($c) => empty(($marcas[$c['id']] ?? [])['revisado'])
            && ($this->option('rehacer') || ! isset($props[$c['id']]))));
        if ($this->option('lote')) {
            $pend = array_values(array_filter($pend, fn ($c) => ($c['lote'] ?? '') === (string) $this->option('lote')));
        }
        if ($this->option('limite') !== null) {
            $pend = array_slice($pend, 0, max(1, (int) $this->option('limite')));
        }
        $this->info(count($pend) . " casos por proponer, con $nEj ejemplos revisados");

        $in = $out = $fallos = 0;
        $bar = $this->output->createProgressBar(count($pend));
        foreach ($pend as $c) {
            $user = "CASO\nAsunto: " . self::limpia((string) $c['asunto'], 150) . (! empty($c['historial']) ? "\nCONVERSACIÓN PREVIA (es un SEGUIMIENTO: se evalúa solo el último mensaje del cliente):\n" . self::limpia((string) $c['historial'], 2500) : '') . "\nCliente: " . self::limpia((string) $c['pregunta'], 1500)
                . "\n(No se te enseña lo que respondió el equipo en su día: decide solo por lo que pide el cliente y por los criterios.)";
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
