<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\GatingService;
use Illuminate\Http\Request;

/**
 * Ajustes del AGENTE DE IA (Claude) para el WhatsApp de soporte.
 *
 * BLOQUE 1: solo guarda la configuración y gestiona el candado (sin clave de API
 * el agente está INACTIVO, en solo lectura). El "cerebro" (ClaudeBrain) y el
 * enganche en el webhook llegan en el Bloque 2.
 *
 * Requiere settings.manage (superadmin / encargado).
 */
class AiSettingsController extends Controller
{
    /**
     * Personalidad por defecto: sale de la entrevista de 10 preguntas de AEME.
     * El usuario la puede editar; si nunca la toca, esto es lo que rige.
     */
    public const PERSONALIDAD_DEF = <<<TXT
Eres el asistente de atención al cliente de AEME Group, la empresa de las etiquetas electrónicas para buffets, tiendas y farmacias. Atiendes por WhatsApp a todo tipo de clientes: hoteles, farmacias, tiendas, supermercados, etc.

TONO Y FORMA
- Trato cercano pero formal. Trata SIEMPRE de USTED. No uses emojis.
- Preséntate de forma natural al empezar, por ejemplo: «Buenos días, le hablo de AEME Group».
- Responde SIEMPRE en el mismo idioma en el que le escriba el cliente (español, inglés o portugués).
- Sé claro y breve.

QUÉ AYUDAS A RESOLVER
- Incidencias habituales de las etiquetas electrónicas: etiquetas que no funcionan, cómo cambiar posiciones, una etiqueta que no va, la antena desconectada, una etiqueta que se ha quedado bloqueada, etc.
- Apóyate en las FAQs, en los documentos de la base de conocimiento y en el historial del propio cliente para responder con nuestra información, sin inventar.

SOLICITUD DE MATERIAL
- Si el cliente pide material, indícale que debe enviar un correo a postventa@aemegroup.com detallando el material que necesita, la cantidad y, a poder ser, una foto de referencia. El equipo de postventa se encargará de gestionar el presupuesto.

LÍNEAS ROJAS (MUY IMPORTANTE)
- NUNCA des precios ni presupuestos.
- NUNCA reveles información interna ni detalles del sistema cloud.
- No inventes: si no tienes la información, dilo y ofrece derivar a una persona del equipo.
TXT;

    /** Modelos ofrecidos (símbolo → se traduce al id real de la API en el Bloque 2). */
    public const MODELOS = ['rapido', 'equilibrado', 'potente'];

    public function handle(Request $request)
    {
        if ($request->isMethod('post')) return $this->save($request);

        $key = (string) Setting::get('ia_api_key', '');
        return response()->json([
            'settings' => [
                'ia_activa'        => (string) Setting::get('ia_activa', '0') === '1',
                'ia_modelo'        => (string) Setting::get('ia_modelo', 'equilibrado'),
                'ia_modo'          => (string) Setting::get('ia_modo', 'borrador'),
                'ia_personalidad'  => (string) Setting::get('ia_personalidad', self::PERSONALIDAD_DEF),
                'ia_solo_en_turno' => (string) Setting::get('ia_solo_en_turno', '1') === '1',
                'ia_tope_dia'      => (int) Setting::get('ia_tope_dia', '200'),
                // La clave NUNCA se devuelve entera: solo si está puesta y sus 4 últimos.
                'api_key_set'      => $key !== '',
                'api_key_hint'     => $key !== '' ? '····' . substr($key, -4) : '',
                // Lector de fotos soporteQA (endpoint externo Base44).
                'soporteqa_activo'  => (string) Setting::get('soporteqa_activo', '0') === '1',
                'soporteqa_url'     => (string) Setting::get('soporteqa_url', \App\Services\SoporteQaClient::URL_DEF),
                'soporteqa_key_set' => ($sqk = (string) Setting::get('soporteqa_key', '')) !== '',
                'soporteqa_key_hint' => $sqk !== '' ? '····' . substr($sqk, -4) : '',
            ],
            'personalidad_def' => self::PERSONALIDAD_DEF,
            // Contexto del candado para la pantalla.
            'nivel_wa' => GatingService::nivelSoporte(),   // ninguno | prueba | real
            'locked'   => $key === '',                     // sin clave → inactivo
        ]);
    }

    /**
     * Guarda SOLO lo que venga (la pantalla puede guardar por secciones). La CLAVE
     * de API se trata con cuidado: vacío = no cambiar; un valor = reemplazar; el
     * literal «__CLEAR__» = borrarla. Así no se pierde al guardar otra cosa.
     */
    protected function save(Request $request)
    {
        if ($request->has('ia_activa')) {
            Setting::put('ia_activa', filter_var($request->input('ia_activa'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }
        if ($request->has('ia_modelo')) {
            $m = (string) $request->input('ia_modelo');
            Setting::put('ia_modelo', in_array($m, self::MODELOS, true) ? $m : 'equilibrado');
        }
        if ($request->has('ia_modo')) {
            Setting::put('ia_modo', (string) $request->input('ia_modo') === 'auto' ? 'auto' : 'borrador');
        }
        if ($request->has('ia_personalidad')) {
            Setting::put('ia_personalidad', mb_substr((string) $request->input('ia_personalidad'), 0, 6000));
        }
        if ($request->has('ia_solo_en_turno')) {
            Setting::put('ia_solo_en_turno', filter_var($request->input('ia_solo_en_turno'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }
        if ($request->has('ia_tope_dia')) {
            Setting::put('ia_tope_dia', (string) max(0, min(100000, (int) $request->input('ia_tope_dia'))));
        }

        if ($request->has('ia_api_key')) {
            $k = trim((string) $request->input('ia_api_key'));
            if ($k === '__CLEAR__') {
                Setting::put('ia_api_key', '');
            } elseif ($k !== '') {
                Setting::put('ia_api_key', $k);
            }
        }

        // --- Lector de fotos soporteQA ---
        if ($request->has('soporteqa_activo')) {
            Setting::put('soporteqa_activo', filter_var($request->input('soporteqa_activo'), FILTER_VALIDATE_BOOLEAN) ? '1' : '0');
        }
        if ($request->has('soporteqa_url')) {
            $u = trim((string) $request->input('soporteqa_url'));
            Setting::put('soporteqa_url', filter_var($u, FILTER_VALIDATE_URL) ? $u : '');
        }
        if ($request->has('soporteqa_key')) {
            $sk = trim((string) $request->input('soporteqa_key'));
            if ($sk === '__CLEAR__') {
                Setting::put('soporteqa_key', '');
            } elseif ($sk !== '') {
                Setting::put('soporteqa_key', $sk);
            }
        }

        $key = (string) Setting::get('ia_api_key', '');
        return response()->json(['ok' => true, 'locked' => $key === '', 'api_key_set' => $key !== '']);
    }
}
