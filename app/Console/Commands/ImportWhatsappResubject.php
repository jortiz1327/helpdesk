<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula los ASUNTOS de los tickets importados del histórico de WhatsApp.
 *
 * El importador dejó muchos asuntos flojos: saludos sueltos («Hola, buenas
 * tardes»), prefijos de saludo («Buenas tardes, tenemos un problema…»),
 * acuses de recibo («Ok, gracias») o el mensaje entero de 200 caracteres.
 *
 * Esto los rehace desde los mensajes YA guardados, SOLO POR REGLAS (sin IA):
 * quita el saludo y el nombre de cortesía, descarta el relleno, busca el primer
 * mensaje CON ENJUNDIA (primero del cliente; si el chat lo abrió el agente, del
 * agente) y lo recorta a un asunto corto y legible.
 *
 * Dry-run por defecto (solo enseña una muestra antes/después). Con --apply escribe.
 *   php artisan wa:resubject                 (previsualiza)
 *   php artisan wa:resubject --apply         (aplica de verdad)
 */
class ImportWhatsappResubject extends Command
{
    protected $signature = 'wa:resubject
        {--apply : Escribe los cambios (por defecto solo previsualiza)}
        {--source=import-wa : Marca en tickets.source a procesar}
        {--sample=30 : Cuántos ejemplos antes/después mostrar en el dry-run}';

    protected $description = 'Mejora los asuntos de los tickets importados del histórico de WhatsApp (por reglas, sin IA)';

    // Saludo al principio (puede ir repetido: «Hola buenas tardes»).
    private const GREET = '/^\s*(hola|buenas(?:\s+(?:tardes|noches|d[ií]as))?|buenos\s+d[ií]as|buen[ao]s?|hi|hey|hello|ola|bon\s+dia|bona\s+tarda|bones|molt\s+bon\s+dia)\b[\s,!¡.:;\-]*/iu';

    // Relleno / acuse puro (todo el mensaje): no sirve como asunto.
    private const FILLER = '/^(muchas\s+gracias|mil\s+gracias|gracias|ok(?:ay)?|vale|perfecto|de\s+acuerdo|genial|estupendo|excelente|correcto|entendido|recibido|thanks?|thank\s+you|i\s+see|ad[ií]os|hasta\s+luego|un\s+saludo|saludos|bien|s[ií]|no|👍)[\s!¡.,:;)\-]*$/iu';

    // Empieza por acuse («Muchas gracias, …», «Ok, lo miro»): es una respuesta, no el asunto.
    private const STARTS_ACK = '/^(muchas\s+gracias|mil\s+gracias|gracias|ok(?:ay)?|vale|perfecto|de\s+acuerdo|genial|estupendo|excelente|correcto|entendido|recibido|thanks?|thank\s+you|i\s+see)\b/iu';

    public function handle(): int
    {
        $source = (string) $this->option('source');
        $apply  = (bool) $this->option('apply');
        $sample = max(0, (int) $this->option('sample'));

        $tickets = DB::table('tickets')->where('source', $source)->orderBy('id')->get(['id', 'subject']);
        $total = $tickets->count();
        if (!$total) {
            $this->warn("No hay tickets con source=$source.");
            return self::SUCCESS;
        }

        $this->info(($apply ? '🖊  APLICANDO' : '👀 DRY-RUN') . " · $total tickets (source=$source)");

        $cambiados = 0; $iguales = 0; $sinCand = 0; $ejemplos = [];
        foreach ($tickets as $t) {
            $msgs = DB::table('messages')->where('ticket_id', $t->id)
                ->orderBy('id')->limit(30)->get(['direction', 'type', 'body']);

            $nuevo = self::asunto($msgs, (string) $t->subject);
            if ($nuevo === null) { $sinCand++; continue; }          // nada aprovechable → no se toca
            if ($nuevo === (string) $t->subject) { $iguales++; continue; }

            if (count($ejemplos) < $sample) $ejemplos[] = [(string) $t->subject, $nuevo];
            if ($apply) DB::table('tickets')->where('id', $t->id)->update(['subject' => $nuevo]);
            $cambiados++;
        }

        if ($ejemplos) {
            $this->table(['Antes', 'Después'], array_map(fn ($e) => [
                mb_strimwidth($e[0], 0, 48, '…'),
                mb_strimwidth($e[1], 0, 48, '…'),
            ], $ejemplos));
        }

        $this->info("Mejorados: $cambiados · Ya estaban bien: $iguales · Sin candidato (intactos): $sinCand");
        if (!$apply) $this->warn('DRY-RUN: no se ha escrito nada. Añade --apply para aplicar.');
        return self::SUCCESS;
    }

    /**
     * Mejor asunto para el ticket, o null si no hay nada mejor que lo que tiene.
     * CONSERVADOR: primero intenta limpiar el asunto que YA tiene (mismo contenido,
     * solo sin el saludo y recortado). Solo si ese asunto es inservible (saludo o
     * acuse puro) baja a re-derivarlo de los mensajes.
     */
    public static function asunto($msgs, string $actual): ?string
    {
        // 1) Limpiar el asunto EXISTENTE. Es lo seguro y cubre la gran mayoría.
        $limpio = self::limpiar($actual);
        if ($limpio !== null) return self::recortar($limpio);

        // 2) El asunto actual no vale (p. ej. «Hola, buenas tardes» / «Ok»). Se busca en
        //    los mensajes: primero cliente (8 primeros), si no, agente (4 primeros).
        $limpiaTexto = fn ($m) => trim(preg_replace('/\s+/', ' ', (string) $m->body));
        $in = []; $out = [];
        foreach ($msgs as $m) {
            if ($m->type !== 'text') continue;
            $b = $limpiaTexto($m);
            if ($b === '' || preg_match('/^\[/', $b)) continue;      // vacío o marcador de media
            if ($m->direction === 'in') $in[] = $b; else $out[] = $b;
        }
        foreach ([array_slice($in, 0, 8), array_slice($out, 0, 4)] as $lista) {
            foreach ($lista as $c) {
                $limpio = self::limpiar($c);
                if ($limpio !== null) return self::recortar($limpio);
            }
        }
        return null;
    }

    /** Quita saludo + nombre de cortesía; null si lo que queda es relleno/acuse/corto. */
    private static function limpiar(string $s): ?string
    {
        $s = trim($s);
        for ($i = 0; $i < 2; $i++) $s = preg_replace(self::GREET, '', $s);   // «Hola buenas tardes»
        $s = ltrim($s, " ,.!¡:;-\t");
        // Nombre de cortesía tras el saludo: «Jordi, …» (una palabra Capitalizada + coma).
        $s = preg_replace('/^\p{Lu}[\p{L}]+\s*,\s+/u', '', $s, 1);
        $s = trim($s);
        if ($s === '') return null;

        // Longitud real sin emojis.
        $sinEmoji = trim(preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}]/u', '', $s));
        if (mb_strlen($sinEmoji) < 12) return null;      // demasiado corto para ser un asunto
        if (preg_match(self::FILLER, $s)) return null;    // relleno puro
        if (preg_match(self::STARTS_ACK, $s)) return null; // empieza por acuse → es una respuesta
        return $s;
    }

    /** Recorta a un asunto: primera frase o ~72 chars, Capitaliza, sin puntuación final. */
    private static function recortar(string $s): string
    {
        if (preg_match('/^(.{20,72}?[.?!])(\s|$)/u', $s, $m)) {          // primera frase razonable
            $s = $m[1];
        } elseif (mb_strlen($s) > 74) {                                  // corta por palabra
            $corte = mb_substr($s, 0, 72);
            $sp = mb_strrpos($corte, ' ');
            $s = ($sp !== false && $sp > 40 ? mb_substr($corte, 0, $sp) : $corte) . '…';
        }
        $s = rtrim(trim($s), '.,;:');
        return mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1);     // Capitaliza la 1ª letra
    }
}
