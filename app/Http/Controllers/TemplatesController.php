<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\GatingService;
use App\Services\WhatsAppService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Portado de api/templates.php — plantillas vía Graph API. Requiere token. */
class TemplatesController extends Controller
{
    public function handle(Request $request, WhatsAppService $wa)
    {
        $wabaId = (string) Setting::get('wa_business_id');

        if ($request->isMethod('get')) {
            [$code, $res] = $wa->graph('GET', $wabaId . '/message_templates', null, [
                'limit'  => 200,
                'fields' => 'name,status,category,language,components,id',
            ]);
            if ($code >= 200 && $code < 300) {
                return response()->json(['ok' => true, 'templates' => $res['data'] ?? []]);
            }
            return response()->json(['ok' => false, 'error' => $res['error']['message'] ?? 'Error al listar'], $code ?: 500);
        }

        if ($request->isMethod('post')) {
            return $this->create($request, $wa, $wabaId);
        }

        if ($request->isMethod('put')) {
            $in = $request->all();
            $id = $request->query('id') ?? ($in['template_id'] ?? '');
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta el id de la plantilla'], 400);
            $components = $this->buildStandard($in);
            if ($components instanceof JsonResponse) return $components;
            $payload = ['components' => $components];
            if (!empty($in['category'])) $payload['category'] = $in['category'];
            [$code, $res] = $wa->graph('POST', (string) $id, $payload);
            if ($code >= 200 && $code < 300) return response()->json(['ok' => true, 'result' => $res]);
            return response()->json(['ok' => false, 'error' => $res['error']['error_user_msg'] ?? $res['error']['message'] ?? 'Error al editar', 'detail' => $res], $code ?: 500);
        }

        if ($request->isMethod('delete')) {
            if ($locked = GatingService::guard('template_delete')) return $locked;
            $name = $request->query('name', '');
            if (!$name) return response()->json(['ok' => false, 'error' => 'Falta el nombre'], 400);
            [$code, $res] = $wa->graph('DELETE', $wabaId . '/message_templates', null, ['name' => $name]);
            if ($code >= 200 && $code < 300) return response()->json(['ok' => true]);
            $err = $res['error']['message'] ?? 'Error al eliminar';
            if ((int) ($res['error']['code'] ?? 0) === 100) {
                $err = 'Meta no permite borrar esta plantilla con este token: la cuenta de WhatsApp es compartida, no propia. Bórrala desde Meta Business Suite → WhatsApp Manager.';
            }
            return response()->json(['ok' => false, 'error' => $err], $code ?: 500);
        }

        return response()->json(['error' => 'Método no permitido'], 405);
    }

    protected function create(Request $request, WhatsAppService $wa, string $wabaId)
    {
        $in       = $request->all();
        $name     = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim((string) ($in['name'] ?? ''))));
        $category = $in['category'] ?? 'UTILITY';
        $lang     = $in['language'] ?? 'es';
        if (!$name) return response()->json(['ok' => false, 'error' => 'Falta el nombre'], 400);

        $type = strtoupper($in['template_type'] ?? 'STANDARD');

        if ($type === 'CAROUSEL') {
            $car = $in['carousel'] ?? [];
            if (empty($car['body'])) return response()->json(['ok' => false, 'error' => 'El texto del mensaje es obligatorio'], 400);
            $cards = $car['cards'] ?? [];
            if (count($cards) < 2) return response()->json(['ok' => false, 'error' => 'Se necesitan al menos 2 tarjetas'], 400);
            $cardComps = [];
            foreach ($cards as $idx => $c) {
                if (empty($c['handle'])) return response()->json(['ok' => false, 'error' => 'La tarjeta ' . ($idx + 1) . ' necesita una imagen'], 400);
                if (empty($c['body'])) return response()->json(['ok' => false, 'error' => 'La tarjeta ' . ($idx + 1) . ' necesita texto'], 400);
                $comps = [
                    ['type' => 'HEADER', 'format' => 'IMAGE', 'example' => ['header_handle' => [$c['handle']]]],
                    ['type' => 'BODY', 'text' => $c['body']],
                ];
                $cb = $this->buildButtons($c['buttons'] ?? []);
                if ($cb) $comps[] = ['type' => 'BUTTONS', 'buttons' => $cb];
                $cardComps[] = ['components' => $comps];
            }
            $components = [
                ['type' => 'BODY', 'text' => $car['body']],
                ['type' => 'CAROUSEL', 'cards' => $cardComps],
            ];
            return $this->postTemplate($wa, $wabaId, ['name' => $name, 'language' => $lang, 'category' => $category, 'components' => $components]);
        }

        if ($type === 'CATALOG') {
            $cat = $in['catalog'] ?? [];
            if (empty($cat['body'])) return response()->json(['ok' => false, 'error' => 'El texto del mensaje es obligatorio'], 400);
            $components = [['type' => 'BODY', 'text' => $cat['body']]];
            if (!empty($cat['footer'])) $components[] = ['type' => 'FOOTER', 'text' => $cat['footer']];
            $components[] = ['type' => 'BUTTONS', 'buttons' => [['type' => 'CATALOG', 'text' => 'Ver catálogo']]];
            return $this->postTemplate($wa, $wabaId, ['name' => $name, 'language' => $lang, 'category' => 'MARKETING', 'components' => $components]);
        }

        $components = $this->buildStandard($in);
        if ($components instanceof JsonResponse) return $components;
        return $this->postTemplate($wa, $wabaId, ['name' => $name, 'language' => $lang, 'category' => $category, 'components' => $components]);
    }

    protected function postTemplate(WhatsAppService $wa, string $wabaId, array $body)
    {
        [$code, $res] = $wa->graph('POST', $wabaId . '/message_templates', $body);
        if ($code >= 200 && $code < 300) return response()->json(['ok' => true, 'result' => $res]);
        return response()->json(['ok' => false, 'error' => $res['error']['error_user_msg'] ?? $res['error']['message'] ?? 'Error al crear', 'detail' => $res], $code ?: 500);
    }

    /** Construye los botones de Meta. */
    protected function buildButtons(?array $arr): array
    {
        $btns = [];
        foreach ($arr ?: [] as $b) {
            $t = $b['type'] ?? 'QUICK_REPLY';
            if ($t === 'URL' && !empty($b['url'])) {
                $btns[] = ['type' => 'URL', 'text' => $b['text'], 'url' => $b['url']];
            } elseif ($t === 'PHONE_NUMBER' && !empty($b['phone'])) {
                $btns[] = ['type' => 'PHONE_NUMBER', 'text' => $b['text'], 'phone_number' => $b['phone']];
            } elseif (!empty($b['text'])) {
                $btns[] = ['type' => 'QUICK_REPLY', 'text' => $b['text']];
            }
        }
        return $btns;
    }

    /** Construye los componentes de una plantilla estándar. Devuelve array o JsonResponse (error 400). */
    protected function buildStandard(array $in): array|JsonResponse
    {
        $components = [];

        $header  = $in['header'] ?? [];
        $hformat = $header['format'] ?? 'NONE';
        if ($hformat === 'TEXT' && !empty($header['text'])) {
            $h = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $header['text']];
            if (preg_match('/\{\{1\}\}/', $header['text']) && !empty($header['example'])) {
                $h['example'] = ['header_text' => [$header['example']]];
            }
            $components[] = $h;
        } elseif ($hformat === 'MEDIA' && !empty($header['media_handle'])) {
            $components[] = ['type' => 'HEADER', 'format' => strtoupper($header['media_format'] ?? 'IMAGE'), 'example' => ['header_handle' => [$header['media_handle']]]];
        }

        $body = $in['body'] ?? [];
        $bodyText = trim($body['text'] ?? '');
        if ($bodyText === '') return response()->json(['ok' => false, 'error' => 'El cuerpo es obligatorio'], 400);
        $bComp = ['type' => 'BODY', 'text' => $bodyText];
        $examples = array_values(array_filter($body['examples'] ?? [], fn ($e) => $e !== '' && $e !== null));
        preg_match_all('/\{\{(\d+)\}\}/', $bodyText, $m);
        $varCount = $m[1] ? max(array_map('intval', $m[1])) : 0;
        if ($varCount > 0) {
            if (count($examples) < $varCount) return response()->json(['ok' => false, 'error' => "Faltan ejemplos para las variables ({$varCount} necesarias)"], 400);
            $bComp['example'] = ['body_text' => [array_slice($examples, 0, $varCount)]];
        }
        $components[] = $bComp;

        if (!empty($in['footer']['text'])) $components[] = ['type' => 'FOOTER', 'text' => $in['footer']['text']];

        $btns = $this->buildButtons($in['buttons'] ?? []);
        if ($btns) $components[] = ['type' => 'BUTTONS', 'buttons' => $btns];

        return $components;
    }
}
