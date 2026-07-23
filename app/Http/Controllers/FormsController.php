<?php

namespace App\Http\Controllers;

use App\Services\ChatService;
use App\Services\FlowsMetaService;
use App\Services\GatingService;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/forms.php — formularios (WhatsApp Flows). Requiere token. */
class FormsController extends Controller
{
    public function handle(Request $request, WhatsAppService $wa, FlowsMetaService $flows)
    {
        $action = $request->query('action', '');

        if ($action === 'stats') {
            $total = DB::table('forms')->count();
            $pub   = DB::table('forms')->where('status', 'published')->count();
            $subs  = DB::table('form_submissions')->count();
            return response()->json(['ok' => true, 'total' => $total, 'published' => $pub, 'drafts' => $total - $pub, 'submissions' => $subs]);
        }

        if ($action === 'submissions') {
            $rows = DB::select("
                SELECT s.id, s.form_id, s.data, s.created_at, f.name AS form_name, c.name AS contact_name, c.wa_id
                FROM form_submissions s
                LEFT JOIN forms f ON f.id = s.form_id
                LEFT JOIN contacts c ON c.id = s.contact_id
                ORDER BY s.id DESC LIMIT 200
            ");
            foreach ($rows as $r) $r->data = json_decode($r->data ?: '{}', true);
            return response()->json(['ok' => true, 'submissions' => $rows]);
        }

        if ($action === 'sync') {
            $waba = (string) \App\Models\Setting::get('wa_business_id');
            [$code, $res] = $wa->graph('GET', $waba . '/flows', null, ['fields' => 'id,name,status']);
            if ($code < 200 || $code >= 300) {
                return response()->json(['ok' => false, 'error' => $res['error']['message'] ?? 'No se pudieron obtener los flujos de Meta']);
            }
            $imported = 0;
            foreach ($res['data'] ?? [] as $fl) {
                if (DB::table('forms')->where('meta_flow_id', $fl['id'])->exists()) continue;
                DB::table('forms')->insert([
                    'name'         => $fl['name'] ?? 'Flujo de Meta',
                    'fields'       => '[]',
                    'status'       => strtolower($fl['status'] ?? 'draft') === 'published' ? 'published' : 'draft',
                    'meta_flow_id' => $fl['id'],
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                $imported++;
            }
            return response()->json(['ok' => true, 'imported' => $imported, 'found' => count($res['data'] ?? [])]);
        }

        if ($action === 'publish' && $request->isMethod('post')) {
            if ($locked = GatingService::guard('flow_publish')) return $locked;
            $id = (int) $request->input('id');
            $form = DB::selectOne('SELECT * FROM forms WHERE id = ?', [$id]);
            if (!$form) return response()->json(['ok' => false, 'error' => 'Formulario no encontrado'], 404);
            if (!json_decode($form->fields ?: '[]', true)) return response()->json(['ok' => false, 'error' => 'El formulario no tiene campos'], 400);
            [$ok, $res, $val] = $flows->publish($form);
            if ($ok) {
                DB::table('forms')->where('id', $id)->update(['meta_flow_id' => $res, 'status' => 'published', 'updated_at' => now()]);
                return response()->json(['ok' => true, 'flow_id' => $res]);
            }
            return response()->json(['ok' => false, 'error' => $res, 'validation' => $val]);
        }

        if ($action === 'send' && $request->isMethod('post')) {
            if ($locked = GatingService::guard('flow_send')) return $locked;
            $id = (int) $request->input('id');
            $contactId = (int) $request->input('contact_id');
            $to = preg_replace('/\D/', '', (string) $request->input('to'));
            $form = DB::selectOne('SELECT * FROM forms WHERE id = ?', [$id]);
            if (!$form || empty($form->meta_flow_id)) return response()->json(['ok' => false, 'error' => 'Publica el formulario en WhatsApp antes de enviarlo'], 400);
            if (!$to && $contactId) $to = DB::table('contacts')->where('id', $contactId)->value('wa_id');
            if (!$to) return response()->json(['ok' => false, 'error' => 'Falta el destinatario'], 400);
            if (!$contactId) $contactId = ChatService::upsertContact($to);
            $token = 'f' . $id . '_' . $contactId;
            $cta = mb_substr(trim((string) $request->input('cta')) ?: 'Ver formulario', 0, 30);
            $body = trim((string) $request->input('body')) ?: (trim($form->description ?? '') ?: '¡Hola! Pulsa abajo para rellenar nuestro formulario.');
            [$code, $r] = $flows->send($to, $form->meta_flow_id, $token, $body, $cta);
            if ($code >= 200 && $code < 300 && !empty($r['messages'][0]['id'])) {
                ChatService::storeMessage($contactId, $to, 'out', 'interactive', '📋 Formulario: ' . $form->name, ['wamid' => $r['messages'][0]['id'], 'status' => 'sent']);
                return response()->json(['ok' => true]);
            }
            return response()->json(['ok' => false, 'error' => $r['error']['error_user_msg'] ?? $r['error']['message'] ?? 'No se pudo enviar el formulario']);
        }

        if ($request->isMethod('get') && $request->query('id')) {
            $f = DB::selectOne('SELECT * FROM forms WHERE id = ?', [(int) $request->query('id')]);
            if (!$f) return response()->json(['ok' => false, 'error' => 'No encontrado'], 404);
            $f->fields = json_decode($f->fields ?: '[]', true);
            return response()->json(['ok' => true, 'form' => $f]);
        }

        if ($request->isMethod('get')) {
            $rows = DB::select("
                SELECT f.id, f.name, f.description, f.status, f.updated_at, f.meta_flow_id,
                       (SELECT COUNT(*) FROM form_submissions s WHERE s.form_id = f.id) AS submissions,
                       JSON_LENGTH(f.fields) AS fields_count
                FROM forms f ORDER BY f.updated_at DESC
            ");
            return response()->json(['ok' => true, 'forms' => $rows]);
        }

        if ($request->isMethod('post')) {
            $id     = (int) $request->input('id');
            $name   = trim((string) $request->input('name')) ?: 'Formulario';
            $desc   = $request->input('description', '');
            $status = $request->input('status', 'draft') === 'published' ? 'published' : 'draft';
            $fields = json_encode($request->input('fields', []), JSON_UNESCAPED_UNICODE);
            if ($id) {
                DB::table('forms')->where('id', $id)->update(['name' => $name, 'description' => $desc, 'fields' => $fields, 'status' => $status, 'updated_at' => now()]);
                return response()->json(['ok' => true, 'id' => $id]);
            }
            $id = DB::table('forms')->insertGetId(['name' => $name, 'description' => $desc, 'fields' => $fields, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
            return response()->json(['ok' => true, 'id' => $id]);
        }

        if ($request->isMethod('delete')) {
            $id = (int) $request->query('id', 0);
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
            DB::table('forms')->where('id', $id)->delete();
            DB::table('form_submissions')->where('form_id', $id)->delete();
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
    }
}
