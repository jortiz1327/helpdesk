<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Services\HtmlSanitizer;
use Illuminate\Http\Request;

/**
 * Plantillas de aviso por correo. Vive en «Configuración de soporte».
 * Solo se editan (el catálogo es fijo: no se crean ni se borran plantillas,
 * porque cada una está enganchada a un evento del código).
 */
class EmailTemplatesController extends Controller
{
    public function handle(Request $request)
    {
        if ($request->isMethod('post')) return $this->save($request);
        return $this->list();
    }

    protected function list()
    {
        $rows = EmailTemplate::orderBy('id')->get(['id', 'key', 'subject', 'body', 'active', 'recipients', 'updated_at']);
        foreach ($rows as $r) {
            [$name, $desc] = EmailTemplate::INFO[$r->key] ?? [$r->key, ''];
            $r->name = $name;
            $r->description = $desc;
        }
        return response()->json([
            'templates'  => $rows,
            'vars'       => EmailTemplate::VARS,
            'recipients' => EmailTemplate::RECIPIENTS,
        ]);
    }

    protected function save(Request $request)
    {
        $tpl = EmailTemplate::where('id', (int) $request->input('id'))->first();
        if (!$tpl) return response()->json(['ok' => false, 'error' => 'Plantilla no encontrada'], 404);

        $subject = trim((string) $request->input('subject'));
        // El cuerpo se SANEA por lista blanca: se guarda y luego se envía por correo.
        $body = HtmlSanitizer::clean((string) $request->input('body'));

        if ($subject === '') return response()->json(['ok' => false, 'error' => 'El asunto es obligatorio'], 400);
        if (trim(HtmlSanitizer::toText($body)) === '') {
            return response()->json(['ok' => false, 'error' => 'El contenido no puede estar vacío'], 400);
        }

        // Destinatarios: solo las claves conocidas, en booleano.
        $dest = [];
        foreach (array_keys(EmailTemplate::RECIPIENTS) as $k) {
            $dest[$k] = filter_var($request->input("recipients.$k", false), FILTER_VALIDATE_BOOLEAN);
        }
        if (!in_array(true, $dest, true)) {
            return response()->json(['ok' => false, 'error' => 'Elige al menos un destinatario'], 400);
        }

        $tpl->update([
            'subject'    => mb_substr($subject, 0, 200),
            'body'       => $body,
            'active'     => filter_var($request->input('active', false), FILTER_VALIDATE_BOOLEAN),
            'recipients' => $dest,
        ]);

        return response()->json(['ok' => true]);
    }
}
