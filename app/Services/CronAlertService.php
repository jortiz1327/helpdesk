<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AVISOS DE CRON: reconoce los correos de crones fallidos, saca sus datos y los
 * agrupa en UN aviso por cron.
 *
 * Un cron roto que corre cada 5 minutos manda ~288 correos al día. Sin agrupar, la bandeja es
 * inservible; agrupados, son 288 ejecuciones de UN aviso con contador.
 *
 * Formatos que debe entender (conviven durante la transición):
 *   · el correo DIRECTO de `noreply@etiquetaselectronicas.es` (el definitivo);
 *   · el mismo envuelto en la notificación de osTicket («Nuevo ticket XXXX creado»).
 * En ambos el cuerpo trae los mismos campos, así que se leen del cuerpo y no del
 * asunto —que en la versión de osTicket lleva SU título, distinto del cron real—.
 */
class CronAlertService
{
    /** Remitentes que sabemos que son máquinas. */
    public const REMITENTES = ['noreply@etiquetaselectronicas.es'];

    /** ¿Este correo es un aviso de cron? */
    public function esAviso(string $subject, string $texto, string $from = ''): bool
    {
        if (in_array(mb_strtolower(trim($from)), self::REMITENTES, true)) return true;
        if (stripos($subject, 'Cron Job Execution Log') !== false) return true;

        // Señal más fiable que cualquier asunto: el cuerpo trae los campos del informe.
        return (bool) preg_match('/Cron\s*Job\s*Name\s*:/i', $texto);
    }

    /**
     * Saca los datos del informe. Devuelve null si no encuentra ni el nombre del cron
     * (sin él no hay nada que agrupar y es mejor tratarlo como correo normal).
     */
    public function parse(string $texto): ?array
    {
        $t = preg_replace('/[ \t]+/', ' ', $texto) ?? $texto;

        $nombre = $this->campo($t, 'Cron Job Name');
        if ($nombre === null || $nombre === '') return null;

        $comando = $this->campo($t, 'URL or Shell Command');
        $estado  = $this->campo($t, 'Status');

        return [
            'cron_name'  => mb_substr($nombre, 0, 190),
            'command'    => $comando,
            'params'     => $this->parametros((string) $comando),
            'expression' => mb_substr((string) $this->campo($t, 'Expression'), 0, 60) ?: null,
            'exit_code'  => mb_substr((string) $this->campo($t, 'HTTP Code | Exit Code'), 0, 12) ?: null,
            'status'     => $estado,
            'executed_at' => $this->fecha((string) $this->campo($t, 'Execution time')),
            'reason'     => $this->motivo($t, (string) $estado),
            'output'     => $this->salida($t),
        ];
    }

    /**
     * Clave de agrupación: NOMBRE + PARÁMETROS. El mismo script corre para varios
     * clientes (`farmacia=scorazon`, `farmacia=mcgallego`), así que agrupar solo por
     * nombre juntaría averías de clientes distintos. Decisión del usuario.
     */
    public function clave(array $d): string
    {
        $base = mb_strtolower(trim($d['cron_name']) . '|' . trim((string) ($d['params'] ?? '')));
        return mb_substr(preg_replace('/\s+/', ' ', $base) ?? $base, 0, 191);
    }

    /**
     * Registra un fallo: crea el aviso si es nuevo o suma una ejecución al que ya
     * existe. Devuelve [ticket_id, alerta_nueva].
     */
    public function registrar(array $d, int $contactId, string $cuerpoHtml, ?Carbon $cuando = null): array
    {
        $clave = $this->clave($d);
        $ahora = $cuando ?? now();

        return DB::transaction(function () use ($d, $clave, $contactId, $cuerpoHtml, $ahora) {
            $alerta = DB::table('cron_alerts')->where('cron_key', $clave)->lockForUpdate()->first();
            $nueva  = false;

            if ($alerta) {
                $ticketId = (int) $alerta->ticket_id;

                // Si estaba cerrado y vuelve a fallar, se reabre: la avería sigue ahí.
                $estado = DB::table('tickets')->where('id', $ticketId)->value('status');
                if (!in_array($estado, TicketService::OPEN_STATUSES, true)) {
                    app(TicketService::class)->setStatus($ticketId, 'abierto', null, false);
                }
            } else {
                $nueva    = true;
                $ticketId = app(TicketService::class)->create([
                    'contact_id' => $contactId,
                    'channel'    => 'cron',
                    'subject'    => mb_substr($d['cron_name'] . ($d['params'] ? ' · ' . $d['params'] : ''), 0, 200),
                ]);

                DB::table('cron_alerts')->insert([
                    'ticket_id' => $ticketId, 'cron_key' => $clave,
                    'cron_name' => $d['cron_name'], 'params' => $d['params'],
                    'expression' => $d['expression'], 'command' => $d['command'],
                    'fails' => 0, 'first_at' => $ahora,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            /*
             * Cada correo es UNA EJECUCIÓN más del histórico, no un ticket nuevo.
             * Los datos ya analizados van en `payload`: el cuerpo del correo trae
             * firmas y cabeceras de reenvío, así que no sirve para resumir el fallo.
             */
            ChatService::storeMessage($contactId, '', 'in', 'text', $cuerpoHtml, [
                'ticket_id'  => $ticketId,
                'channel'    => 'cron',
                'is_html'    => true,
                'status'     => 'received',
                'created_at' => $ahora,
                'payload'    => [
                    'exit_code' => $d['exit_code'],
                    'reason'    => $d['reason'],
                    'output'    => mb_substr((string) $d['output'], 0, 500),
                ],
            ]);

            DB::table('cron_alerts')->where('cron_key', $clave)->update([
                'fails'          => DB::raw('fails + 1'),
                'last_at'        => $ahora,
                'last_exit_code' => $d['exit_code'],
                'last_reason'    => mb_substr((string) $d['reason'], 0, 190) ?: null,
                'last_output'    => $d['output'],
                'expression'     => $d['expression'],
                'updated_at'     => now(),
            ]);
            DB::table('tickets')->where('id', $ticketId)->update(['last_message_at' => $ahora]);

            return [$ticketId, $nueva];
        });
    }

    /* ------------------------------ lectura de campos ----------------------- */

    /**
     * Lee «Etiqueta: valor». El informe llega a veces con el valor en la misma línea
     * y a veces en la siguiente (según el cliente de correo), así que se acepta
     * cualquier separación y se corta al llegar a la siguiente etiqueta conocida.
     */
    protected function campo(string $texto, string $etiqueta): ?string
    {
        $siguientes = 'Cron Job Name|URL or Shell Command|Expression|Command Type|Regular Expression|'
            . 'Execution time|HTTP Code \| Exit Code|Status|More info|First 5 KB output|Std Output|Std Error';

        $re = '/' . preg_quote($etiqueta, '/') . '\s*:\s*(.*?)(?=\s*(?:' . $siguientes . ')\s*:|$)/su';
        if (!preg_match($re, $texto, $m)) return null;

        $v = trim(preg_replace('/\s+/', ' ', $m[1]) ?? $m[1]);
        return $v !== '' && $v !== '-' ? $v : null;
    }

    /** Los parámetros del comando: lo que distingue a un cliente de otro. */
    protected function parametros(string $comando): ?string
    {
        if ($comando === '') return null;
        preg_match_all('/\b([a-z_][a-z0-9_]*)=([^\s]+)/i', $comando, $m, PREG_SET_ORDER);

        $out = [];
        foreach ($m as $p) {
            // La clave de acceso no identifica al cliente y no debe acabar en pantalla.
            if (preg_match('/^(k|key|token|pass|password|secret)$/i', $p[1])) continue;
            $out[] = $p[1] . '=' . $p[2];
        }
        return $out ? mb_substr(implode(' ', $out), 0, 190) : null;
    }

    /** Por qué falló, en una línea: «exceeded the timeout of 400 seconds», «General error». */
    protected function motivo(string $texto, string $estado): ?string
    {
        if (preg_match('/exceeded the timeout of\s*(\d+)\s*seconds/i', $texto, $m)) {
            return 'Superó el tiempo límite de ' . $m[1] . " segundos";
        }
        // «Status: Failed General error» → lo que sigue a Failed, si lo hay.
        if (preg_match('/\bFailed\b[ :.-]*([^\n]{3,120}?)(?=\s*(?:The process|More info|First 5 KB|Std Output)|$)/i', $estado . ' ' . $texto, $m)) {
            $extra = trim($m[1]);
            if ($extra !== '' && !preg_match('/^(the process)/i', $extra)) return mb_substr($extra, 0, 190);
        }
        return $estado ? mb_substr($estado, 0, 190) : null;
    }

    /** La salida del script, que suele decir de verdad qué pasó. */
    protected function salida(string $texto): ?string
    {
        if (!preg_match('/Std Output\s*(.*?)(?=Std Error|Saludos cordiales|$)/su', $texto, $m)) return null;

        $s = trim(preg_replace('/\s+/', ' ', strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $m[1]))) ?? '');
        return $s !== '' ? mb_substr($s, 0, 2000) : null;
    }

    /** Fecha de ejecución del informe («2026-07-21 11:00:00»). */
    protected function fecha(string $v): ?Carbon
    {
        if (!preg_match('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?/', $v, $m)) return null;
        try { return Carbon::parse($m[0]); } catch (\Throwable $e) { return null; }
    }
}
