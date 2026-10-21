<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * DETALLE de las acciones de CONTACTOS y CONFIGURACIÓN para el registro de acciones.
 *
 * Igual que TicketActivity, pero genérico: cada endpoint declara qué tablas toca. El
 * middleware hace una FOTO de esas filas antes de la acción y, al terminar, aquí se
 * compara con cómo han quedado: qué se creó, borró o cambió, campo a campo y con
 * nombres legibles («Color: Azul → Rojo», «SLA de respuesta: 4 h → 2 h»).
 *
 * Los SECRETOS (contraseñas IMAP/SMTP, token y App Secret de WhatsApp, verify token)
 * nunca se escriben: como mucho «Token: cambiado».
 */
class ConfigActivity
{
    /*
     * Endpoint → apartado, tablas que toca, y la frase de resumen cuando hay varios
     * cambios. 'settings' = claves de la tabla settings que puede tocar. 'escribe' =
     * acciones que escriben aunque lleguen por GET (varios controladores no miran el
     * método). 'acciones' = frases fijas para acciones sin diff útil.
     */
    public const ENDPOINTS = [
        // --- Contactos ---
        'contact.php'  => ['section' => 'Contactos', 'tablas' => ['contacts', 'contact_labels'], 'porIds' => true, 'resumen' => 'Editó un contacto'],
        'contacts.php' => ['section' => 'Contactos', 'tablas' => ['contacts', 'contact_labels'], 'porIds' => true, 'resumen' => 'Editó contactos'],
        'labels.php'   => ['section' => 'Contactos', 'tablas' => ['labels'], 'resumen' => 'Actualizó las etiquetas de contactos',
                           'acciones' => ['reorder' => 'Reordenó las etiquetas de contactos']],

        // --- Configuración ---
        'support_settings.php' => ['section' => 'Configuración', 'tablas' => ['ticket_categories', 'canned_responses'], 'resumen' => 'Actualizó la configuración de soporte'],
        'ticket_priorities.php'=> ['section' => 'Configuración', 'tablas' => ['ticket_priorities'], 'resumen' => 'Actualizó las prioridades', 'escribe' => ['save', 'delete']],
        'ticket_labels.php'    => ['section' => 'Configuración', 'tablas' => ['ticket_labels'], 'resumen' => 'Actualizó las etiquetas de tickets', 'escribe' => ['save', 'delete']],
        'ticket_rules.php'     => ['section' => 'Configuración', 'tablas' => ['ticket_rules'], 'resumen' => 'Actualizó las reglas automáticas', 'escribe' => ['save', 'delete']],
        'faqs.php'             => ['section' => 'Configuración', 'tablas' => ['faqs'], 'resumen' => 'Actualizó las preguntas frecuentes', 'escribe' => ['save', 'delete', 'reorder'],
                                   'acciones' => ['reorder' => 'Reordenó las preguntas frecuentes']],
        'email_bans.php'       => ['section' => 'Configuración', 'tablas' => ['email_bans'], 'resumen' => 'Actualizó los correos bloqueados', 'escribe' => ['save', 'delete']],
        'email_templates.php'  => ['section' => 'Configuración', 'tablas' => ['email_templates'], 'resumen' => 'Actualizó los avisos automáticos'],
        'email.php'            => ['section' => 'Configuración', 'tablas' => ['email_accounts'], 'settings' => ['email_footer', 'email_footer_active'], 'resumen' => 'Actualizó el buzón de correo'],
        'ticket_fields.php'    => ['section' => 'Configuración', 'tablas' => ['ticket_custom_fields'], 'resumen' => 'Actualizó los campos personalizados', 'escribe' => ['save_def', 'delete_def', 'save_values']],
        'ticket_settings.php'  => ['section' => 'Configuración', 'tablas' => [], 'settings' => ['ticket_default_status', 'ticket_lock_minutes', 'ticket_autoclose_days',
                                   'ticket_autoclose_notify', 'csat_active', 'login_max_user', 'login_max_ip', 'login_lock_minutes', 'login_lock_message'],
                                   'resumen' => 'Actualizó el comportamiento de los tickets'],
        'features.php'         => ['section' => 'Configuración', 'tablas' => [], 'settings' => ['sla_active', 'sla_alerts_active', 'sla_escalate_active', 'csat_active',
                                   'ticket_autoclose_days', 'ticket_lock_minutes'], 'resumen' => 'Cambió funciones del sistema', 'escribe' => ['set']],
        'business_hours.php'   => ['section' => 'Configuración', 'tablas' => ['business_hours', 'holidays'], 'settings' => ['sla_active', 'sla_alerts_active', 'sla_escalate_active', 'sla_escalate_priority'],
                                   'resumen' => 'Actualizó el horario y el SLA', 'escribe' => ['save', 'add_holiday', 'del_holiday', 'toggle_sla', 'toggle_alerts', 'save_escalate']],
        'settings.php'         => ['section' => 'Configuración', 'tablas' => [], 'settings' => ['wa_verify_token', 'business_name', 'consent_message', 'account_verified',
                                   'consent_enabled', 'outbound_paused', 'daily_send_cap', 'wa_price_marketing', 'wa_price_utility', 'wa_price_authentication', 'wa_price_service'],
                                   'resumen' => 'Actualizó los ajustes de WhatsApp'],
        'whatsapp_numbers.php' => ['section' => 'Configuración', 'tablas' => ['whatsapp_numbers'], 'resumen' => 'Actualizó los números de WhatsApp', 'escribe' => ['save', 'delete']],
    ];

    /*
     * Tabla → cómo se nombra (artículo + sustantivo), qué columna la identifica, y
     * etiquetas de los campos. Lo que no esté aquí sale con el nombre de la columna.
     */
    private const TABLAS = [
        'contacts'             => ['n' => 'el contacto', 'label' => ['name', 'email', 'wa_id'], 'campos' => [
            'name' => 'Nombre', 'email' => 'Correo', 'wa_id' => 'WhatsApp', 'country_code' => 'País', 'note' => 'Nota',
            'opted_out' => 'Baja de comunicaciones', 'consent' => 'Consentimiento', 'bot_off' => 'Bot desactivado',
            'assigned_to' => 'Agente', 'sede_id' => 'Sede', 'empresa' => 'Empresa', 'tienda' => 'Tienda', 'cargo' => 'Cargo',
            'provincia' => 'Provincia', 'comentarios' => 'Comentarios']],
        'labels'               => ['n' => 'la etiqueta de contactos', 'label' => ['name'], 'campos' => ['name' => 'Nombre', 'color' => 'Color']],
        'ticket_categories'    => ['n' => 'la categoría', 'label' => ['name'], 'campos' => [
            'key' => 'Clave', 'name' => 'Nombre', 'description' => 'Descripción', 'signature' => 'Firma', 'color' => 'Color',
            'active' => 'Activa', 'sla_hours' => 'SLA (h)', 'sla_response_hours' => 'SLA de respuesta (h)',
            'sla_resolve_hours' => 'SLA de resolución (h)', 'use_shift' => 'Usa turnos']],
        'canned_responses'     => ['n' => 'la respuesta predefinida', 'label' => ['title', 'shortcut'], 'campos' => [
            'shortcut' => 'Atajo', 'title' => 'Título', 'body' => 'Texto', 'active' => 'Activa']],
        'ticket_priorities'    => ['n' => 'la prioridad', 'label' => ['name'], 'campos' => [
            'key' => 'Clave', 'name' => 'Nombre', 'description' => 'Descripción', 'color' => 'Color', 'active' => 'Activa',
            'is_default' => 'Por defecto', 'sla_response_mins' => 'SLA de respuesta (min)', 'sla_resolve_mins' => 'SLA de resolución (min)']],
        'ticket_labels'        => ['n' => 'la etiqueta de tickets', 'label' => ['name'], 'campos' => ['name' => 'Nombre', 'color' => 'Color', 'active' => 'Activa']],
        'ticket_rules'         => ['n' => 'la regla automática', 'label' => ['name'], 'campos' => [
            'name' => 'Nombre', 'active' => 'Activa', 'channel' => 'Canal', 'match' => 'Coincidencia', 'conditions' => 'Condiciones',
            'actions' => 'Acciones', 'stop' => 'Detener las siguientes']],
        'faqs'                 => ['n' => 'la pregunta frecuente', 'label' => ['question'], 'campos' => [
            'section' => 'Sección', 'question' => 'Pregunta', 'answer' => 'Respuesta', 'hint' => 'Pista', 'keywords' => 'Palabras clave',
            'category_id' => 'Categoría', 'active' => 'Activa']],
        'email_bans'           => ['n' => 'el bloqueo de correo', 'label' => ['email'], 'campos' => ['email' => 'Correo', 'active' => 'Activo', 'notes' => 'Notas']],
        'email_templates'      => ['n' => 'el aviso automático', 'label' => ['key'], 'campos' => [
            'subject' => 'Asunto', 'body' => 'Texto', 'active' => 'Activo', 'recipients' => 'Destinatarios']],
        'email_accounts'       => ['n' => 'el buzón', 'label' => ['email', 'funcion'], 'campos' => [
            'funcion' => 'Función', 'email' => 'Correo', 'from_name' => 'Nombre del remitente', 'imap_host' => 'Servidor IMAP',
            'imap_port' => 'Puerto IMAP', 'imap_encryption' => 'Cifrado IMAP', 'imap_user' => 'Usuario IMAP', 'imap_password' => 'Contraseña IMAP',
            'smtp_host' => 'Servidor SMTP', 'smtp_port' => 'Puerto SMTP', 'smtp_encryption' => 'Cifrado SMTP', 'smtp_user' => 'Usuario SMTP',
            'smtp_password' => 'Contraseña SMTP', 'active' => 'Activo']],
        'ticket_custom_fields' => ['n' => 'el campo personalizado', 'label' => ['label', 'key'], 'campos' => [
            'key' => 'Clave', 'label' => 'Nombre', 'type' => 'Tipo', 'options' => 'Opciones', 'required' => 'Obligatorio', 'active' => 'Activo']],
        'holidays'             => ['n' => 'el festivo', 'label' => ['name', 'date'], 'campos' => ['date' => 'Fecha', 'name' => 'Nombre']],
        'whatsapp_numbers'     => ['n' => 'el número de WhatsApp', 'label' => ['label', 'display_number', 'phone_number_id'], 'campos' => [
            'label' => 'Etiqueta', 'phone_number_id' => 'ID del número', 'funcion' => 'Función', 'entorno' => 'Entorno', 'token' => 'Token',
            'app_secret' => 'App Secret', 'waba_id' => 'WABA ID', 'app_id' => 'App ID', 'active' => 'Activo']],
    ];

    /** Columnas que nunca se cuentan (internas, contadores, marcas de tiempo). */
    private const IGNORAR = ['id', 'created_at', 'updated_at', 'position', 'last_check_at', 'last_uid', 'fail_uid', 'fail_count',
        'last_message', 'last_time', 'unread', 'views', 'helpful_yes', 'helpful_no', 'opted_out_at', 'consent_at', 'created_by', 'display_number'];

    /** Secretos: se dice que cambiaron, nunca el valor. */
    private const SECRETOS = ['imap_password', 'smtp_password', 'token', 'app_secret', 'wa_verify_token', 'wa_app_secret'];

    /** Campos sí/no. */
    private const BOOL = ['active', 'required', 'stop', 'use_shift', 'is_default', 'opted_out', 'consent', 'bot_off',
        'sla_active', 'sla_alerts_active', 'sla_escalate_active', 'csat_active', 'ticket_autoclose_notify', 'account_verified',
        'consent_enabled', 'outbound_paused', 'email_footer_active'];

    /** Textos largos: se dice que cambiaron, sin volcarlos. */
    private const LARGOS = ['body', 'answer', 'signature', 'description', 'conditions', 'actions', 'comentarios', 'note', 'notes',
        'options', 'recipients', 'consent_message', 'email_footer', 'login_lock_message', 'keywords', 'hint'];

    /** Claves de settings con nombre legible. */
    private const CLAVES = [
        'ticket_default_status' => 'Estado inicial de los tickets', 'ticket_lock_minutes' => 'Bloqueo entre agentes (min)',
        'ticket_autoclose_days' => 'Cierre automático (días)', 'ticket_autoclose_notify' => 'Avisar del cierre automático',
        'csat_active' => 'Encuesta de satisfacción', 'login_max_user' => 'Intentos máx. por usuario', 'login_max_ip' => 'Intentos máx. por IP',
        'login_lock_minutes' => 'Bloqueo de acceso (min)', 'login_lock_message' => 'Mensaje de acceso bloqueado',
        'sla_active' => 'SLA', 'sla_alerts_active' => 'Avisos de SLA', 'sla_escalate_active' => 'Escalado de SLA',
        'sla_escalate_priority' => 'Prioridad de escalado', 'business_name' => 'Nombre del negocio', 'consent_message' => 'Mensaje de consentimiento',
        'account_verified' => 'Cuenta de Meta verificada', 'consent_enabled' => 'Pedir consentimiento', 'outbound_paused' => 'Envíos pausados',
        'daily_send_cap' => 'Tope diario de envíos', 'wa_price_marketing' => 'Tarifa marketing', 'wa_price_utility' => 'Tarifa utilidad',
        'wa_price_authentication' => 'Tarifa autenticación', 'wa_price_service' => 'Tarifa servicio', 'email_footer' => 'Pie de correo',
        'email_footer_active' => 'Pie de correo activo', 'wa_verify_token' => 'Verify Token',
    ];

    private const DIAS = [0 => 'Domingo', 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public static function cubre(string $endpoint): bool
    {
        return isset(self::ENDPOINTS[$endpoint]);
    }

    /** ¿Escribe esta petición? (POST/DELETE… o una acción que escribe aunque llegue por GET). */
    public static function escribe(string $endpoint, Request $request, string $action): bool
    {
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) return true;
        return in_array($action, self::ENDPOINTS[$endpoint]['escribe'] ?? [], true);
    }

    /* ------------------------------- la foto ------------------------------- */

    public static function snapshot(string $endpoint, Request $request): array
    {
        $cfg = self::ENDPOINTS[$endpoint];
        $foto = ['t' => [], 's' => [], 'h' => null, 'v' => []];

        if (!empty($cfg['porIds'])) {
            // Siempre las dos claves (aunque vacías): en un alta la foto previa no tiene
            // filas, y así el diff ve el contacto nuevo en la de después.
            $ids = self::idsContacto($request);
            $foto['t']['contacts'] = $ids ? self::filas('contacts', fn ($q) => $q->whereIn('id', $ids)) : [];
            $foto['t']['contact_labels'] = $ids ? self::etiquetasContacto($ids) : [];
        } else {
            foreach ($cfg['tablas'] as $t) {
                if ($t === 'business_hours') { $foto['h'] = self::horario(); continue; }
                $foto['t'][$t] = self::filas($t);
            }
        }
        if (!empty($cfg['settings'])) {
            $foto['s'] = self::settings($cfg['settings']);
        }
        // Valores de campos personalizados de UN ticket (se editan desde la ficha).
        if ($endpoint === 'ticket_fields.php' && ($tid = (int) $request->input('ticket_id'))) {
            $foto['v'] = DB::table('ticket_field_values')->where('ticket_id', $tid)->pluck('value', 'field_id')->all();
        }
        return $foto;
    }

    /* ------------------------------ descripción ----------------------------- */

    /** [frase, referencia, detalles[]], o null si no cambió nada. */
    public static function describir(string $endpoint, string $action, Request $request, array $antes, array $resp): ?array
    {
        $cfg = self::ENDPOINTS[$endpoint];

        // Acciones con frase propia, sin diff.
        if ($endpoint === 'email.php' && $action === 'send_test') {
            return ['Envió un correo de prueba', null, array_filter(['A: ' . self::corta($request->input('to'), 80)])];
        }
        if ($endpoint === 'email.php' && in_array($action, ['quarantine_discard', 'quarantine_retry'], true)) {
            $q = DB::table('email_quarantine')->where('id', (int) $request->input('id'))->first(['subject', 'from_email']);
            return [$action === 'quarantine_retry' ? 'Reintentó un correo en cuarentena' : 'Descartó un correo en cuarentena', null,
                $q ? array_values(array_filter(['De: ' . $q->from_email, $q->subject ? 'Asunto: «' . self::corta($q->subject, 90) . '»' : null])) : []];
        }
        if (isset($cfg['acciones'][$action])) {
            return [$cfg['acciones'][$action], null, []];
        }
        if ($endpoint === 'contacts.php') {
            return self::contactosMasivo($action, $request, $antes, $resp);
        }
        if ($endpoint === 'contact.php' && in_array($action, ['bulk', 'import'], true)) {
            $n = (int) ($resp['added'] ?? 0);
            return [$action === 'import' ? 'Importó contactos desde un archivo' : 'Añadió contactos en bloque', null, array_values(array_filter([
                "Nuevos: $n",
                isset($resp['reused']) ? 'Actualizados: ' . (int) $resp['reused'] : null,
                isset($resp['dup']) ? 'Repetidos: ' . (int) $resp['dup'] : null,
                !empty($resp['invalid']) ? 'No válidos: ' . (is_array($resp['invalid']) ? count($resp['invalid']) : (int) $resp['invalid']) : null,
                !empty($resp['tags_new']) ? 'Etiquetas nuevas: ' . (is_array($resp['tags_new']) ? implode(', ', $resp['tags_new']) : (int) $resp['tags_new']) : null,
            ]))];
        }
        if ($endpoint === 'ticket_fields.php' && $action === 'save_values') {
            return self::valoresTicket($request, $antes['v'] ?? []);
        }

        $despues = self::snapshot($endpoint, self::conIdNuevo($request, $resp));
        $cambios = [];   // [tipo, tabla, nombre, campos[]]

        foreach ($antes['t'] as $tabla => $filasAntes) {
            if ($tabla === 'contact_labels') continue;
            $cambios = [...$cambios, ...self::diffTabla($tabla, $filasAntes, $despues['t'][$tabla] ?? [])];
        }
        // Etiquetas de un contacto (pivot).
        if (isset($antes['t']['contact_labels'])) {
            foreach ($despues['t']['contact_labels'] ?? [] as $cid => $ahora) {
                $eran = $antes['t']['contact_labels'][$cid] ?? [];
                $puso = array_diff($ahora, $eran); $quito = array_diff($eran, $ahora);
                if (!$puso && !$quito) continue;
                $nombre = self::nombreFila('contacts', $despues['t']['contacts'][$cid] ?? ['id' => $cid]);
                $cambios[] = ['editado', 'contacts', $nombre, array_values(array_filter([
                    $puso ? 'Etiquetas añadidas: ' . self::nombres('labels', $puso) : null,
                    $quito ? 'Etiquetas quitadas: ' . self::nombres('labels', $quito) : null,
                ]))];
            }
        }
        // Horario semanal (se borra y se reescribe entero: se compara por día).
        if ($antes['h'] !== null) {
            $lineas = [];
            foreach (self::DIAS as $d => $nom) {
                if ($d === 7) continue;
                $a = $antes['h'][$d] ?? 'Cerrado'; $b = $despues['h'][$d] ?? 'Cerrado';
                if ($a !== $b) $lineas[] = "$nom: $a → $b";
            }
            if ($lineas) $cambios[] = ['editado', 'business_hours', '', $lineas];
        }
        // Ajustes clave/valor.
        $lineasAjustes = [];
        foreach (array_unique([...array_keys($antes['s']), ...array_keys($despues['s'])]) as $k) {
            $a = $antes['s'][$k] ?? null; $b = $despues['s'][$k] ?? null;
            if ((string) $a === (string) $b) continue;
            $nombre = self::CLAVES[$k] ?? $k;
            $lineasAjustes[] = in_array($k, self::SECRETOS, true) ? "$nombre: cambiado" : "$nombre: " . self::valor($k, $a) . ' → ' . self::valor($k, $b);
        }
        if ($lineasAjustes) $cambios[] = ['ajustes', '', '', $lineasAjustes];

        if (!$cambios) return null;

        // UN cambio sobre UNA fila: frase concreta («Editó la categoría «Soporte»»).
        if (count($cambios) === 1 && $cambios[0][0] !== 'ajustes' && $cambios[0][1] !== 'business_hours') {
            [$tipo, $tabla, $nombre, $campos] = $cambios[0];
            $verbo = ['nuevo' => 'Creó', 'borrado' => 'Eliminó', 'editado' => 'Editó'][$tipo];
            return ["$verbo " . self::TABLAS[$tabla]['n'] . ($nombre !== '' ? " «{$nombre}»" : ''), null, $campos];
        }
        // Solo ajustes: la frase del apartado y la lista de claves.
        $detalles = [];
        foreach ($cambios as [$tipo, $tabla, $nombre, $campos]) {
            if ($tipo === 'ajustes' || $tabla === 'business_hours') { $detalles = [...$detalles, ...$campos]; continue; }
            $verbo = ['nuevo' => 'Creó', 'borrado' => 'Eliminó', 'editado' => 'Editó'][$tipo];
            $detalles[] = "$verbo " . self::TABLAS[$tabla]['n'] . " «{$nombre}»" . ($campos ? ' — ' . implode(' · ', $campos) : '');
        }
        $frase = $cfg['resumen'];
        if ($endpoint === 'features.php' && count($lineasAjustes) === 1) $frase = 'Cambió una función del sistema';
        return [$frase, null, array_slice($detalles, 0, 15)];
    }

    /* --------------------------- contactos (bloque) --------------------------- */

    private static function contactosMasivo(string $action, Request $request, array $antes, array $resp): ?array
    {
        $contactos = $antes['t']['contacts'] ?? [];
        $nombres = array_map(fn ($c) => self::nombreFila('contacts', $c), $contactos);
        $lista = $nombres ? 'Contactos: ' . implode(', ', array_slice($nombres, 0, 10)) . (count($nombres) > 10 ? ' y ' . (count($nombres) - 10) . ' más' : '') : null;
        $n = count($contactos);
        $plural = $n === 1 ? '1 contacto' : "$n contactos";

        switch ($action) {
            case 'bulk_label':
                $et = self::nombres('labels', [(int) $request->input('label_id')]);
                return [($request->input('mode') === 'remove' ? "Quitó la etiqueta «{$et}» a " : "Puso la etiqueta «{$et}» a ") . $plural, null, array_filter([$lista])];
            case 'set_optout':
                return [($request->boolean('value') ? 'Dio de baja de comunicaciones a ' : 'Reactivó las comunicaciones de ') . $plural, null, array_filter([$lista])];
            case 'bulk_phonebook':
                $ag = DB::table('phonebooks')->where('id', (int) $request->input('phonebook_id'))->value('name') ?? 'una agenda';
                return ["Añadió $plural a la agenda «{$ag}»", null, array_values(array_filter([
                    isset($resp['added']) ? 'Nuevos en la agenda: ' . (int) $resp['added'] : null, $lista]))];
            case 'merge':
                $keep  = $contactos[(int) $request->input('keep_id')] ?? null;
                $merge = $contactos[(int) $request->input('merge_id')] ?? null;
                return ['Fusionó el contacto «' . ($merge ? self::nombreFila('contacts', $merge) : '?') . '» en «' . ($keep ? self::nombreFila('contacts', $keep) : '?') . '»', null,
                    $merge ? array_values(array_filter([
                        $merge['email'] ? 'Correo del fusionado: ' . $merge['email'] : null,
                        $merge['wa_id'] ? 'WhatsApp del fusionado: ' . $merge['wa_id'] : null,
                    ])) : []];
        }
        return null;
    }

    /** Valores de los campos personalizados de un ticket. */
    private static function valoresTicket(Request $request, array $antes): ?array
    {
        $tid = (int) $request->input('ticket_id');
        $ahora = DB::table('ticket_field_values')->where('ticket_id', $tid)->pluck('value', 'field_id')->all();
        $nombres = DB::table('ticket_custom_fields')->pluck('label', 'id')->all();
        $lineas = [];
        foreach (array_unique([...array_keys($antes), ...array_keys($ahora)]) as $fid) {
            $a = $antes[$fid] ?? null; $b = $ahora[$fid] ?? null;
            if ((string) $a === (string) $b) continue;
            $lineas[] = ($nombres[$fid] ?? "Campo #$fid") . ': ' . self::corta($a === null || $a === '' ? '(vacío)' : $a, 60) . ' → ' . self::corta($b === null || $b === '' ? '(vacío)' : $b, 60);
        }
        if (!$lineas) return null;
        $t = DB::table('tickets')->where('id', $tid)->first(['code', 'subject']);
        $code = $t->code ?? "#$tid";
        return ["Editó los campos personalizados de $code", $t ? $t->code . ($t->subject ? ' · ' . self::corta($t->subject, 60) : '') : null, $lineas];
    }

    /* ------------------------------- el diff -------------------------------- */

    /** Cambios de una tabla: [tipo, tabla, nombre, campos[]] por fila. */
    private static function diffTabla(string $tabla, array $antes, array $despues): array
    {
        $out = [];
        foreach ($despues as $id => $fila) {
            if (!isset($antes[$id])) {
                // Alta: con qué valores nació (los campos cortos con nombre, sin secretos).
                $campos = [];
                foreach (self::TABLAS[$tabla]['campos'] ?? [] as $col => $nom) {
                    $v = $fila[$col] ?? null;
                    if ($v === null || $v === '' || in_array($col, [...self::SECRETOS, ...self::LARGOS, ...self::TABLAS[$tabla]['label']], true)) continue;
                    $campos[] = "$nom: " . self::valor($col, $v);
                }
                $out[] = ['nuevo', $tabla, self::nombreFila($tabla, $fila), array_slice($campos, 0, 8)];
                continue;
            }
            $campos = [];
            foreach ($fila as $col => $val) {
                if (in_array($col, self::IGNORAR, true)) continue;
                $prev = $antes[$id][$col] ?? null;
                if ((string) $prev === (string) $val) continue;
                $nom = self::TABLAS[$tabla]['campos'][$col] ?? ucfirst(str_replace('_', ' ', $col));
                if (in_array($col, self::SECRETOS, true))      $campos[] = "$nom: cambiado";
                elseif (in_array($col, self::LARGOS, true))    $campos[] = "$nom: modificado";
                else $campos[] = "$nom: " . self::valor($col, $prev) . ' → ' . self::valor($col, $val);
            }
            if ($campos) $out[] = ['editado', $tabla, self::nombreFila($tabla, $fila), $campos];
        }
        foreach ($antes as $id => $fila) {
            if (!isset($despues[$id])) $out[] = ['borrado', $tabla, self::nombreFila($tabla, $fila), []];
        }
        return $out;
    }

    /** Valor legible: sí/no, nombres en vez de ids, recortado. */
    private static function valor(string $col, $v): string
    {
        if ($v === null || $v === '') return '(vacío)';
        if (in_array($col, self::BOOL, true)) return in_array((string) $v, ['1', 'true', 'on', 'yes'], true) ? 'Sí' : 'No';
        $fk = ['category_id' => ['ticket_categories', 'name'], 'assigned_to' => ['users', 'name'], 'sede_id' => ['sedes', 'name'],
               'sla_escalate_priority' => ['ticket_priorities', 'name', 'key']][$col] ?? null;
        if ($fk) {
            $n = DB::table($fk[0])->where($fk[2] ?? 'id', $v)->value($fk[1]);
            if ($n !== null) return (string) $n;
        }
        if ($col === 'ticket_default_status') return TicketService::STATUSES[$v] ?? (string) $v;
        return self::corta($v, 60);
    }

    private static function nombreFila(string $tabla, array $fila): string
    {
        if ($tabla === 'email_templates' && isset($fila['key'])) {
            return \App\Models\EmailTemplate::INFO[$fila['key']][0] ?? $fila['key'];
        }
        foreach (self::TABLAS[$tabla]['label'] ?? ['name'] as $c) {
            if (!empty($fila[$c])) return self::corta($fila[$c], 70);
        }
        return '#' . ($fila['id'] ?? '?');
    }

    /* ------------------------------- lecturas ------------------------------- */

    /** Filas de una tabla como [id => columnas], sin secretos en claro (solo su huella). */
    private static function filas(string $tabla, ?\Closure $filtro = null): array
    {
        static $cols = [];
        $cols[$tabla] ??= Schema::getColumnListing($tabla);
        $q = DB::table($tabla);
        if ($filtro) $filtro($q);
        $out = [];
        foreach ($q->limit(2000)->get() as $r) {
            $a = (array) $r;
            foreach (self::SECRETOS as $s) {
                if (array_key_exists($s, $a)) $a[$s] = $a[$s] === null ? null : hash('sha256', (string) $a[$s]);
            }
            $out[$a['id']] = $a;
        }
        return $out;
    }

    private static function settings(array $claves): array
    {
        $out = DB::table('settings')->whereIn('key', $claves)->pluck('value', 'key')->all();
        foreach (self::SECRETOS as $s) {
            if (isset($out[$s])) $out[$s] = hash('sha256', (string) $out[$s]);
        }
        return $out;
    }

    /** Horario por día: «09:00–14:00, 16:00–19:00». */
    private static function horario(): array
    {
        $out = [];
        foreach (DB::table('business_hours')->orderBy('weekday')->orderBy('opens')->get() as $r) {
            $tramo = substr((string) $r->opens, 0, 5) . '–' . substr((string) $r->closes, 0, 5);
            $d = (int) $r->weekday === 7 ? 0 : (int) $r->weekday;
            $out[$d] = isset($out[$d]) ? $out[$d] . ', ' . $tramo : $tramo;
        }
        return $out;
    }

    private static function idsContacto(Request $request): array
    {
        $ids = [];
        foreach (['contact_id', 'keep_id', 'merge_id', 'id'] as $k) {
            $v = $request->input($k);
            if (is_scalar($v) && (int) $v > 0) $ids[] = (int) $v;
        }
        foreach ((array) $request->input('contact_ids', []) as $v) {
            if (is_scalar($v) && (int) $v > 0) $ids[] = (int) $v;
        }
        return array_values(array_unique(array_slice($ids, 0, 500)));
    }

    private static function etiquetasContacto(array $ids): array
    {
        $out = array_fill_keys($ids, []);
        foreach (DB::table('contact_labels')->whereIn('contact_id', $ids)->get(['contact_id', 'label_id']) as $r) {
            $out[(int) $r->contact_id][] = (int) $r->label_id;
        }
        return $out;
    }

    /** Alta de un contacto: la foto de «después» debe incluir el id recién creado. */
    private static function conIdNuevo(Request $request, array $resp): Request
    {
        if (!empty($resp['id']) && !$request->input('contact_id') && !$request->input('id')) {
            $r = clone $request;
            $r->merge(['id' => (int) $resp['id']]);
            return $r;
        }
        return $request;
    }

    private static function nombres(string $tabla, array $ids): string
    {
        return DB::table($tabla)->whereIn('id', $ids)->orderBy('name')->pluck('name')->implode(', ') ?: '—';
    }

    private static function corta($s, int $max): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        return mb_strlen($s) > $max ? rtrim(mb_substr($s, 0, $max - 1)) . '…' : $s;
    }
}
