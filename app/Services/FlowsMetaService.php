<?php

namespace App\Services;

use App\Models\Setting;

/** WhatsApp Flows (formularios nativos de Meta). Portado de includes/flows_meta.php. */
class FlowsMetaService
{
    const FLOW_JSON_VERSION = '7.0';

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
        $waba = (string) Setting::get('wa_business_id');
        $fields = json_decode($form->fields ?: '[]', true) ?: [];
        $flowJson = json_encode($this->buildFlowJson($fields, $form->name), JSON_UNESCAPED_UNICODE);
        $flowId = $form->meta_flow_id ?? null;

        if ($flowId) {
            [$c, $r] = $this->wa->graph('POST', (string) $flowId, ['flow_json' => $flowJson]);
        } else {
            [$c, $r] = $this->wa->graph('POST', $waba . '/flows', [
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

        [$pc, $pr] = $this->wa->graph('POST', $flowId . '/publish');
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
