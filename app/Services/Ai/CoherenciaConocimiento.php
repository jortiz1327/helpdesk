<?php

namespace App\Services\Ai;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/**
 * Revisión periódica del conocimiento de la IA: busca CONTRADICCIONES entre lo que decide la dirección (los «hechos» de abajo) y lo que dicen
 * los manuales, las reglas, las correcciones, las plantillas y las respuestas humanas antiguas de los tickets indexados.
 *
 *  - Manuales, reglas y correcciones base (ficheros del repositorio), correcciones validadas y plantillas: solo se INFORMA (las corrige una persona).
 *  - Respuestas del equipo en tickets antiguos que contradicen un hecho: se marcan como DESFASADOS (solo sirven para entender el problema,
 *    no para copiar la respuesta), igual que ya hace AiKnowledge::desfase().
 *  - También se comprueba que cada regla importante esté escrita donde tiene que estar (contradicción por omisión).
 *
 * Sin llamadas a ningún proveedor de IA: es texto y expresiones regulares, no gasta nada. Lo lanza `ai:revisar-conocimiento` cada semana y el
 * botón «Revisar ahora» de Ajustes › Asistente IA. Cuando una decisión de la dirección cambie, se cambia aquí el hecho (y su prueba).
 */
class CoherenciaConocimiento
{
    private const AJUSTE = 'ai_revision_conocimiento';
    private const EMAIL = '/[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}/';
    /** Cada cuántos días corre la revisión (bootstrap/app.php); se enseña en Ajustes. */
    public const CADA_DIAS = 7;

    /**
     * Hechos vigentes. 'ambito': dónde se comprueba (fichero, correccion, plantilla, ticket). 'prohibido' y 'salvo' se aplican a cada
     * línea ya normalizada (minúsculas y sin tildes). Una línea que cumple 'prohibido' y NO 'salvo' es una contradicción.
     *
     * 'contexto' (opcional): además, ese patrón debe aparecer en la línea o en las 3 anteriores.
     *
     * @return array<string, array{titulo:string, ambito:array<int,string>, prohibido?:string, salvo?:string, contexto?:string, correo_ajeno?:bool}>
     */
    public static function hechos(): array
    {
        return [
            'correo-ajeno' => [
                'titulo' => 'El único correo que se da al cliente es el de postventa',
                'ambito' => ['fichero', 'correccion', 'plantilla'],
                'correo_ajeno' => true,
            ],
            'garantia-con-correo' => [
                'titulo' => 'Para garantías no se da ningún correo al cliente',
                'ambito' => ['fichero', 'correccion', 'plantilla', 'ticket'],
                'prohibido' => '/garant[^\n]{0,120}[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}|[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}[^\n]{0,120}garant/',
                'salvo' => '/\bnunca\b|\bno (se )?(da|des|facilit|indiques)|sin dar|\bni el correo|no derives|no (la )?envies/',
            ],
            'varta-compatible' => [
                'titulo' => 'Las pilas VARTA no son compatibles (Duracell puede dar problemas de grosor)',
                'ambito' => ['fichero', 'correccion', 'plantilla', 'ticket'],
                'prohibido' => '/\b(varta|duracell)\b[^.\n]{0,60}\b(compatibles?|valen|sirven|funcionan bien|recomend\w+)\b|\b(compatibles?|recomendad\w+|recomendamos)\b[^.\n]{0,60}\b(varta|duracell)\b/',
                'salvo' => '/\bno\b|incompatible|problema|evit|grosor|grues|nunca|ojo/',
            ],
            'pila-otra-referencia' => [
                'titulo' => 'La pila de las etiquetas es la CR2450 (interna AM-BZ2450)',
                'ambito' => ['fichero', 'correccion', 'plantilla'],
                'prohibido' => '/\bcr ?(?!2450\b)\d{4}\b/',
                'salvo' => '/\bno (es|son|vale|valen|sirve)\b/',
            ],
            'reinicio-minutos' => [
                'titulo' => 'El reinicio de la etiqueta es con las pilas fuera 15 minutos',
                'ambito' => ['fichero', 'correccion', 'plantilla'],
                // «30min», «30 min» y «30 minutos» en una línea; el tema (pila o reinicio) puede estar en la propia línea o en las 3 anteriores
                // (los pasos de una plantilla van uno por línea: «Retire las pilas. / Espere 30min.»).
                'prohibido' => '/\b(?!15\b)\d+ ?min(utos?)?\b/',
                'contexto' => '/pila|reinici/',
            ],
            'anadir-etiqueta-solo-plataforma' => [
                'titulo' => 'Conectividad › Etiquetas › Añadir etiqueta es solo del administrador de la plataforma (al cliente se le dice Posiciones › No encuentro la etiqueta)',
                'ambito' => ['fichero', 'correccion', 'plantilla', 'ticket'],
                // Solo la ruta de Conectividad: «Añadir etiqueta» también existe, con otro sentido, en el plano, los temáticos, las comunicaciones o los eventos.
                'prohibido' => '/conectividad[^\n]{0,60}anadir etiqueta|anadir etiqueta[^\n]{0,60}conectividad/',
                'salvo' => '/\bsolo\b|\bnunca\b|plataforma|no se lo|administrador|superadmin/',
            ],
            'farmacia-garantia-expofarm' => [
                'titulo' => 'Las garantías de farmacia las gestiona Expofarm; no se da correo ni el de postventa',
                'ambito' => ['fichero', 'correccion', 'plantilla'],
                'prohibido' => '/farmacia[^\n]{0,160}garant[^\n]{0,160}(@|escriba a|postventa)|garant[^\n]{0,160}farmacia[^\n]{0,160}(@|escriba a|postventa)/',
                'salvo' => '/\bnunca\b|\bno (se )?(da|des|derives)|sin dar|\bni el correo|expofarm/',
            ],
        ];
    }

    /**
     * Reglas que tienen que estar escritas en un sitio concreto (ruta relativa a resources/, texto que debe contener). Si falta, el
     * manual y las reglas se contradicen por omisión.
     *
     * @return array<int, array{ruta:string, texto:string, titulo:string}>
     */
    public static function omisiones(): array
    {
        return [
            ['ruta' => 'ai/prompt-reglas.md', 'texto' => 'postventa@aemegroup.com', 'titulo' => 'Las reglas dan el correo de postventa para los pedidos de material'],
            ['ruta' => 'ai/prompt-reglas.md', 'texto' => 'tres fotos', 'titulo' => 'Las reglas piden las tres fotos antes de pasar a Garantías'],
            ['ruta' => 'ai/prompt-reglas.md', 'texto' => 'garantias', 'titulo' => 'Las reglas conocen la categoría Garantías'],
            ['ruta' => 'ai/prompt-reglas.md', 'texto' => 'postventa', 'titulo' => 'Las reglas conocen la categoría Postventa'],
            ['ruta' => 'ai/prompt-soporte.md', 'texto' => 'cr2450', 'titulo' => 'El prompt de soporte dice la referencia de la pila (CR2450)'],
            ['ruta' => 'ai-docs/soporte/pilas-y-baterias.md', 'texto' => 'varta', 'titulo' => 'El manual de pilas dice que VARTA no vale'],
            ['ruta' => 'ai-docs/soporte/protocolo-garantias-reinicio-etiqueta.md', 'texto' => 'tres fotos', 'titulo' => 'El protocolo de garantías pide las tres fotos'],
            ['ruta' => 'ai-docs/soporte/reglas-de-derivacion.md', 'texto' => 'tres fotos', 'titulo' => 'Las reglas de derivación (manual) piden las tres fotos'],
            ['ruta' => 'ai-docs/soporte/reglas-de-derivacion.md', 'texto' => 'postventa@aemegroup.com', 'titulo' => 'Las reglas de derivación (manual) dan el correo de postventa'],
        ];
    }

    public static function normalizar(string $s): string
    {
        $n = \Normalizer::normalize($s, \Normalizer::FORM_D);
        $n = preg_replace('/\p{Mn}/u', '', $n === false || $n === null ? $s : $n);
        return mb_strtolower((string) $n);
    }

    /**
     * Líneas de un texto que contradicen algún hecho del ámbito dado.
     *
     * @param  array<int,string>|null  $permitidos  correos que se pueden dar (por defecto config('ai.correos_permitidos'))
     * @return array<int, array{hecho:string, titulo:string, linea:int, texto:string}>
     */
    public static function contradicciones(string $texto, string $ambito, ?array $permitidos = null): array
    {
        $permitidos ??= self::correosPermitidos();
        $out = [];
        $lineas = explode("\n", $texto);
        foreach ($lineas as $i => $linea) {
            if (trim($linea) === '') {
                continue;
            }
            $n = self::normalizar($linea);
            $ventana = self::normalizar(implode("\n", array_slice($lineas, max(0, $i - 3), min(3, $i) + 1)));
            foreach (self::hechos() as $clave => $h) {
                if (! in_array($ambito, $h['ambito'], true)) {
                    continue;
                }
                $hit = false;
                if (! empty($h['correo_ajeno'])) {
                    if (preg_match_all(self::EMAIL, $n, $m)) {
                        foreach ($m[0] as $correo) {
                            $hit = $hit || ! in_array($correo, $permitidos, true);
                        }
                    }
                } elseif (preg_match($h['prohibido'], $n) && ! (isset($h['salvo']) && preg_match($h['salvo'], $n))
                    && (! isset($h['contexto']) || preg_match($h['contexto'], $ventana))) {
                    $hit = true;
                }
                if ($hit) {
                    $out[] = ['hecho' => $clave, 'titulo' => $h['titulo'], 'linea' => $i + 1, 'texto' => mb_substr(trim($linea), 0, 180)];
                }
            }
        }
        return $out;
    }

    /** @return array<int,string> */
    private static function correosPermitidos(): array
    {
        try {
            $c = config('ai.correos_permitidos');
        } catch (\Throwable $e) {
            $c = null;
        }
        return array_map('strtolower', is_array($c) && $c ? $c : ['postventa@aemegroup.com']);
    }

    /** Manuales, reglas y correcciones base del repositorio. */
    public static function revisarFicheros(): array
    {
        $out = [];
        $base = resource_path();
        $ficheros = glob($base . '/ai/*.md') ?: [];
        $dir = $base . '/ai-docs';
        if (is_dir($dir)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile() && in_array(strtolower($f->getExtension()), ['md', 'txt'], true)) {
                    $ficheros[] = $f->getPathname();
                }
            }
        }
        foreach ($ficheros as $ruta) {
            $rel = str_replace('\\', '/', substr($ruta, strlen($base) + 1));
            foreach (self::contradicciones((string) file_get_contents($ruta), 'fichero') as $h) {
                $out[] = $h + ['donde' => 'resources/' . $rel . ':' . $h['linea']];
            }
        }
        $json = $base . '/ai/correcciones-base.json';
        if (is_file($json)) {
            foreach ((array) json_decode((string) file_get_contents($json), true) as $c) {
                foreach (self::contradicciones((string) ($c['contenido'] ?? ''), 'fichero') as $h) {
                    $out[] = $h + ['donde' => 'criterio base «' . ($c['clave'] ?? '?') . '»'];
                }
            }
        }
        foreach (self::omisiones() as $o) {
            $ruta = $base . '/' . $o['ruta'];
            if (! is_file($ruta) || ! str_contains(self::normalizar((string) file_get_contents($ruta)), self::normalizar($o['texto']))) {
                $out[] = ['hecho' => 'omision', 'titulo' => $o['titulo'], 'linea' => 0, 'texto' => 'Falta «' . $o['texto'] . '» en resources/' . $o['ruta'], 'donde' => 'resources/' . $o['ruta']];
            }
        }
        return $out;
    }

    /**
     * Correcciones y plantillas indexadas (solo informa) y respuestas del equipo en tickets (marca como desfasadas las que contradicen un hecho).
     *
     * @return array{hallazgos: array<int,array>, tickets_marcados: int}
     */
    public static function revisarBase(bool $marcar = true): array
    {
        $hallazgos = [];
        DB::table('ai_documents')->whereIn('source_type', ['correccion', 'plantilla'])->orderBy('id')->chunkById(200, function ($docs) use (&$hallazgos) {
            foreach ($docs as $d) {
                foreach (self::contradicciones((string) $d->content, $d->source_type) as $h) {
                    $hallazgos[] = $h + ['donde' => ($d->source_type === 'correccion' ? 'corrección ' : 'plantilla ') . ($d->title ?: '#' . $d->source_id)];
                }
            }
        });
        $marcados = 0;
        DB::table('ai_documents')->where('source_type', 'ticket')->where('desfasado', 0)->orderBy('id')->chunkById(300, function ($docs) use ($marcar, &$marcados) {
            foreach ($docs as $d) {
                $p = strpos((string) $d->content, 'Respuesta del equipo:');
                if ($p !== false && self::contradicciones(substr((string) $d->content, $p), 'ticket')) {
                    $marcados++;
                    if ($marcar) {
                        DB::table('ai_documents')->where('id', $d->id)->update(['desfasado' => 1]);
                    }
                }
            }
        });
        return ['hallazgos' => $hallazgos, 'tickets_marcados' => $marcados];
    }

    /** Pasada completa y guarda el resultado para enseñarlo en Ajustes. */
    public static function ejecutar(bool $marcar = true): array
    {
        $f = self::revisarFicheros();
        $b = self::revisarBase($marcar);
        $res = [
            'fecha' => now()->toDateTimeString(),
            'ficheros' => array_slice($f, 0, 40),
            'base' => array_slice($b['hallazgos'], 0, 40),
            'total' => count($f) + count($b['hallazgos']),
            'tickets_marcados' => $b['tickets_marcados'],
        ];
        if ($marcar) {
            Setting::put(self::AJUSTE, json_encode($res, JSON_UNESCAPED_UNICODE));
        }
        return $res;
    }

    /** Lo último que se revisó y cómo está la memoria ahora (para la tarjeta de Ajustes). */
    public static function resumen(): array
    {
        $u = json_decode((string) Setting::get(self::AJUSTE, ''), true);
        $dias = is_array($u) && ! empty($u['fecha']) ? (int) \Carbon\Carbon::parse($u['fecha'])->startOfDay()->diffInDays(now()->startOfDay()) : null;
        return [
            'ultima' => is_array($u) ? $u : null,
            'dias' => $dias,
            'cada_dias' => self::CADA_DIAS,
            'nivel' => $u === null || $u === false || $dias === null ? 'sin_revisar' : (($u['total'] ?? 0) > 0 ? 'contradicciones' : ($dias > self::CADA_DIAS * 2 ? 'retrasada' : 'ok')),
            'tickets_desfasados' => DB::table('ai_documents')->where('source_type', 'ticket')->where('desfasado', 1)->count(),
            'tickets_excluidos' => DB::table('ai_documents')->where('source_type', 'ticket')->where('desfasado', 2)->count(),
            'tickets_total' => DB::table('ai_documents')->where('source_type', 'ticket')->count(),
        ];
    }
}
