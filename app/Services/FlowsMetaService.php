<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\DB;

/** WhatsApp Flows (formularios nativos de Meta). Portado de includes/flows_meta.php. */
class FlowsMetaService
{
    const FLOW_JSON_VERSION = '7.0';

    /**
     * Traduce una respuesta cruda de formulario a pares legibles.
     * Meta devuelve las claves normalizadas y, en desplegables/radio/checkbox, el
     * ID de la opción (índice), no su texto. Aquí lo mapeamos con la definición del
     * formulario a [{label: pregunta, value: respuesta}] en el orden de los campos.
     */
    public static function etiquetar(int $formId, array $data): array
    {
        $fields = json_decode((string) (DB::table('forms')->where('id', $formId)->value('fields') ?: '[]'), true) ?: [];
        $labels = [];   // name => etiqueta (pregunta)
        $opts   = [];   // name => [id => texto de la opción]
        $orden  = [];   // orden de aparición de los campos
        foreach ($fields as $f) {
            $name = preg_replace('/[^a-z0-9_]/', '_', strtolower($f['key'] ?? ''));
            if ($name === '' || in_array(($f['type'] ?? 'text'), ['paragraph', 'caption'], true)) continue;
            $labels[$name] = trim((string) ($f['label'] ?? $f['key'] ?? $name)) ?: $name;
            $orden[] = $name;
            foreach (($f['options'] ?? []) as $i => $opt) {   // mismo índice que buildFlowJson
                $t = trim((string) $opt);
                if ($t === '') continue;
                $opts[$name][(string) $i] = $t;
            }
        }

        // Recorre en el orden del formulario; los campos que no estén, al final.
        $claves = array_values(array_unique(array_merge(array_intersect($orden, array_keys($data)), array_keys($data))));
        $out = [];
        foreach ($claves as $k) {
            if (!array_key_exists($k, $data)) continue;
            $v = $data[$k];
            if (is_array($v)) {   // checkbox: varias opciones
                $v = implode(', ', array_map(fn ($x) => $opts[$k][(string) $x] ?? (string) $x, $v));
            } elseif (isset($opts[$k][(string) $v])) {
                $v = $opts[$k][(string) $v];
            }
            $out[] = ['label' => $labels[$k] ?? $k, 'value' => (string) $v];
        }
        return $out;
    }

    public function __construct(protected WhatsAppService $wa) {}

    /** Traduce nuestros campos al Flow JSON de Meta (un formulario en una pantalla). */
    public function buildFlowJson(array $fields, ?string $title): array
    {
        $children = [];
        $payload  = [];

        foreach ($fields as $f) {
            $type  = $f['type'] ?? 'text';
            $name  = preg_replace('/[^a-z0-9_]/', '_', strtolower($f['key'] ?? 'campo'));
            $label = (string) ($f['label'] ?? '');
            $req   = !empty($f['required']);

            switch ($type) {
                case 'paragraph':
                    $children[] = ['type' => 'TextBody', 'text' => $label ?: ' '];
                    break;
                case 'caption':
                    $children[] = ['type' => 'TextCaption', 'text' => $label ?: ' '];
                    break;
                case 'text': case 'email': case 'phone': case 'number': case 'password':
                    $map = ['text' => 'text', 'email' => 'email', 'phone' => 'phone', 'number' => 'number', 'password' => 'password'];
                    $children[] = ['type' => 'TextInput', 'name' => $name, 'label' => $label, 'input-type' => $map[$type], 'required' => $req];
                    $payload[$name] = '${form.' . $name . '}';
                    break;
                case 'textarea':
                    $children[] = ['type' => 'TextArea', 'name' => $name, 'label' => $label, 'required' => $req];
                    $payload[$name] = '${form.' . $name . '}';
                    break;
                case 'date':
                    $children[] = ['type' => 'DatePicker', 'name' => $name, 'label' => $label, 'required' => $req];
                    $payload[$name] = '${form.' . $name . '}';
                    break;
                case 'dropdown': case 'radio': case 'checkbox':
                    $ds = [];
                    foreach (($f['options'] ?? []) as $i => $opt) {
                        $t = trim((string) $opt);
                        if ($t === '') continue;
                        $ds[] = ['id' => (string) $i, 'title' => $t];
                    }
                    if (!$ds) break;
                    $compType = $type === 'dropdown' ? 'Dropdown' : ($type === 'radio' ? 'RadioButtonsGroup' : 'CheckboxGroup');
                    $children[] = ['type' => $compType, 'name' => $name, 'label' => $label, 'required' => $req, 'data-source' => $ds];
                    $payload[$name] = '${form.' . $name . '}';
                    break;
            }
        }

        $children[] = [
            'type'  => 'Footer',
            'label' => 'Enviar',
            'on-click-action' => ['name' => 'complete', 'payload' => $payload],
        ];

        return [
            'version' => self::FLOW_JSON_VERSION,
            'screens' => [[
                'id'       => 'FORM',
                'title'    => mb_substr($title ?: 'Formulario', 0, 30),
                'terminal' => true,
                'layout'   => [
                    'type'     => 'SingleColumnLayout',
                    'children' => [[
                        'type'     => 'Form',
                        'name'     => 'form',
                        'children' => $children,
                    ]],
                ],
            ]],
        ];
    }

    /**
     * Publica el formulario como Flow en Meta.
     * @return array{0:bool,1:string,2:array} [ok, flowIdOrError, validationErrors]
     */
    public function publish(object $form): array
    {
        // WABA y token del número (por-función), no del ajuste global vacío (mismo bug
        // que Plantillas/sync de formularios: iba a «/flows» sin WABA delante).
        $n = \App\Models\WhatsAppNumber::conWaba('campanas');
        $wa = $n ? $this->wa->paraNumero($n) : $this->wa;
        $waba = $n?->waba_id ? (string) $n->waba_id : (string) Setting::get('wa_business_id');
        $fields = json_decode($form->fields ?: '[]', true) ?: [];
        $flowJson = json_encode($this->buildFlowJson($fields, $form->name), JSON_UNESCAPED_UNICODE);
        $flowId = $form->meta_flow_id ?? null;

        if ($flowId) {
            [$c, $r] = $wa->graph('POST', (string) $flowId, ['flow_json' => $flowJson]);
        } else {
            [$c, $r] = $wa->graph('POST', $waba . '/flows', [
                'name'       => mb_substr($form->name ?: 'Formulario', 0, 200),
                'categories' => ['LEAD_GENERATION'],
                'flow_json'  => $flowJson,
            ]);
            if ($c >= 200 && $c < 300 && !empty($r['id'])) $flowId = $r['id'];
        }

        if ($c < 200 || $c >= 300 || !$flowId) {
            return [false, $r['error']['error_user_msg'] ?? $r['error']['message'] ?? 'No se pudo crear el flow en Meta', $r['validation_errors'] ?? []];
        }
        if (!empty($r['validation_errors'])) {
            $msg = $r['validation_errors'][0]['message'] ?? 'El formulario tiene errores de formato';
            return [false, 'Meta rechazó el formulario: ' . $msg, $r['validation_errors']];
        }

        [$pc, $pr] = $wa->graph('POST', $flowId . '/publish');
        if ($pc >= 200 && $pc < 300) return [true, (string) $flowId, []];
        return [false, $pr['error']['error_user_msg'] ?? $pr['error']['message'] ?? 'No se pudo publicar el flow', []];
    }

    /** Envía un Flow publicado a un contacto (mensaje interactivo, ventana de 24h). */
    public function send(string $to, string $flowId, string $flowToken, string $bodyText, string $cta): array
    {
        return $this->wa->sendInteractive($to, [
            'type'   => 'flow',
            'body'   => ['text' => $bodyText],
            'action' => [
                'name'       => 'flow',
                'parameters' => [
                    'flow_message_version' => '3',
                    'flow_token'           => $flowToken,
                    'flow_id'              => $flowId,
                    'flow_cta'             => $cta,
                    'flow_action'          => 'navigate',
                    'flow_action_payload'  => ['screen' => 'FORM'],
                ],
            ],
        ]);
    }
}
