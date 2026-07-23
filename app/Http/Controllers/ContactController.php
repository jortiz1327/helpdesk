<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/contact.php — nombre/nota/etiquetas de un contacto. Requiere token. */
class ContactController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');
        $id = (int) $request->input('contact_id', 0);
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta contact_id'], 400);

        if ($action === 'save' && $request->isMethod('post')) {
            $data = $request->all();
            $upd  = [];

            if (array_key_exists('name', $data)) $upd['name'] = trim((string) $data['name']) ?: null;
            if (array_key_exists('note', $data)) $upd['note'] = $data['note'];

            // Correo: opcional, pero si viene tiene que ser válido.
            if (array_key_exists('email', $data)) {
                $email = mb_strtolower(trim((string) $data['email']));
                if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return response()->json(['ok' => false, 'error' => 'El correo no es válido'], 400);
                }
                $upd['email'] = $email ?: null;
            }

            /*
             * Teléfono: se compone «código de país + número» (ambos solo dígitos) y se
             * guarda ENTERO en wa_id, que es lo que usa WhatsApp. El país se guarda
             * además aparte para poder volver a partirlo al editar.
             */
            if (array_key_exists('phone', $data) || array_key_exists('country_code', $data)) {
                $cc = preg_replace('/\D+/', '', (string) ($data['country_code'] ?? ''));
                $ph = preg_replace('/\D+/', '', (string) ($data['phone'] ?? ''));
                $wa = $ph !== '' ? $cc . $ph : null;

                if ($wa !== null && strlen($wa) > 20) {
                    return response()->json(['ok' => false, 'error' => 'El teléfono es demasiado largo'], 400);
                }
                // wa_id es único: no se puede robar el número de otro contacto.
                if ($wa !== null && DB::table('contacts')->where('wa_id', $wa)->where('id', '!=', $id)->exists()) {
                    return response()->json(['ok' => false, 'error' => 'Ese teléfono ya pertenece a otro contacto'], 409);
                }

                $upd['country_code'] = $cc ?: null;
                $upd['wa_id']        = $wa;
            }

            // Un contacto sin correo NI teléfono se quedaría inlocalizable.
            if (array_key_exists('email', $upd) || array_key_exists('wa_id', $upd)) {
                $c = DB::table('contacts')->where('id', $id)->first(['email', 'wa_id']);
                $email = array_key_exists('email', $upd) ? $upd['email'] : ($c->email ?? null);
                $wa    = array_key_exists('wa_id', $upd) ? $upd['wa_id'] : ($c->wa_id ?? null);
                if (!$email && !$wa) {
                    return response()->json(['ok' => false, 'error' => 'Indica al menos un correo o un teléfono'], 400);
                }
            }

            if ($upd) DB::table('contacts')->where('id', $id)->update($upd);

            return response()->json(['ok' => true]);
        }

        if ($action === 'labels' && $request->isMethod('post')) {
            $labelIds = array_map('intval', (array) $request->input('label_ids', []));
            DB::table('contact_labels')->where('contact_id', $id)->delete();
            if ($labelIds) {
                $rows = array_map(fn ($lid) => ['contact_id' => $id, 'label_id' => $lid], $labelIds);
                DB::table('contact_labels')->insertOrIgnore($rows);
            }
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false, 'error' => 'Acción no válida'], 400);
    }
}
