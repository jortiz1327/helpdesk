<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Detecta mensajes de cliente que piden o parecen necesitar atención ya (hotel sin servicio, evento o servicio de hoy, petición
 * expresa de urgencia). Sin IA y sin gasto: reglas sobre el texto. Solo AVISA al agente; no cambia prioridad ni nada del ticket.
 *
 * Es deliberadamente prudente: prefiere un aviso de menos a gritar «urgente» en cada mensaje.
 */
class Urgency
{
    /** «Todo o casi todo está parado». */
    private const ALCANCE = '/\b(sin servicio|no funciona (nada|ninguna|ninguno)|nada funciona|ninguna (etiqueta|pantalla|antena)|ningun(a)? .{0,20}(funciona|cambia|conecta)|todas? las (etiquetas|pantallas|antenas)|todo (esta )?(caido|parado|apagado)|(etiquetas|pantallas|antenas) (apagadas|caidas|en blanco)|se ha caido|no se ve nada)\b/u';
    /** Piden prisa expresamente. */
    private const PRISA = '/\b(urgente|urgentisimo|urgencia|con urgencia|ahora mismo|ya mismo|inmediat[oa]|cuanto antes|lo antes posible|asap|de inmediato|no podemos esperar|es critico|critico)\b/u';
    private const SIN_PRISA = '/\b(no es urgente|sin urgencia|no corre prisa|no hay prisa|no hay urgencia|cuando podais|cuando puedan|sin prisa)\b/u';
    /** Servicio, turno o evento en marcha. */
    private const SERVICIO = '/\b(desayuno|comida|cena|buffet|servicio|turno|cierre de turno|evento|boda|banquete|congreso|apertura|inauguracion|comunion|catering)\b/u';
    private const PROBLEMA = '/\b(no funciona|no funcionan|no cambia|no cambian|no carga|no cargan|sin conexion|no conecta|no conectan|caid[oa]s?|apagad[oa]s?|error|fallo|falla|fallan|no salen|no se ven|no se actualiza|no se actualizan|bloquead[oa]s?|roto|rotas?)\b/u';
    /** Es para hoy o para ya. */
    private const HOY = '/\b(hoy|ahora|esta (manana|tarde|noche)|en (una|dos|tres|\d+) horas?|esta misma|manana a primera|mismo dia)\b/u';

    /**
     * @return array{level:string,reasons:string[]}|null  null si no hay motivo; level: alta | media
     */
    public static function detectar(string $texto): ?array
    {
        $t = Str::lower(Str::ascii(strip_tags($texto)));
        if (mb_strlen(trim($t)) < 8) {
            return null;
        }
        $alcance = (bool) preg_match(self::ALCANCE, $t);
        $prisa = (bool) preg_match(self::PRISA, $t) && ! preg_match(self::SIN_PRISA, $t);
        $servicio = (bool) preg_match(self::SERVICIO, $t);
        $problema = (bool) preg_match(self::PROBLEMA, $t);
        $hoy = (bool) preg_match(self::HOY, $t);

        $alta = $alcance || ($prisa && $problema) || ($servicio && $problema && $hoy);
        $media = $prisa || ($servicio && $problema);
        if (! $alta && ! $media) {
            return null;
        }
        $motivos = [];
        if ($alcance) $motivos[] = 'Parece que no funciona nada o casi nada';
        if ($prisa) $motivos[] = 'Pide urgencia';
        if ($servicio && $problema && ($hoy || $alta)) $motivos[] = 'Afecta a un servicio o evento en marcha';
        elseif ($servicio && $problema) $motivos[] = 'Menciona un servicio o evento';
        return ['level' => $alta ? 'alta' : 'media', 'reasons' => array_values(array_unique($motivos))];
    }
}
