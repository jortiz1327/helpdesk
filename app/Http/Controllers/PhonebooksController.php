<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Portado de api/phonebooks.php — agendas de difusión. Requiere token. */
class PhonebooksController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');

        if ($request->isMethod('post') && $action === 'add') {
            return $this->add($request);
        }
        if ($request->isMethod('post')) {
            return $this->save($request);
        }
        if ($request->isMethod('get') && $request->query('id')) {
            return $this->detail((int) $request->query('id'));
        }
        if ($request->isMethod('get')) {
            $rows = DB::select("
                SELECT p.*, (SELECT COUNT(*) FROM phonebook_contacts pc WHERE pc.phonebook_id = p.id) AS contacts
                FROM phonebooks p ORDER BY p.updated_at DESC
            ");
            return response()->json(['ok' => true, 'phonebooks' => $rows]);
        }
        if ($request->isMethod('delete')) {
            return $this->delete($request);
        }
        return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

    protected function add(Request $request)
    {
        $pbId = (int) $request->input('phonebook_id');
        if (!$pbId) return response()->json(['ok' => false, 'error' => 'Falta la agenda'], 400);

        $rows = [];
        $import = $request->input('import', '');
        if ($import === 'contacts') {
            $rows = DB::select('SELECT wa_id, name FROM contacts');
        } elseif ($import === 'label' && $request->input('label_id')) {
            $rows = DB::select('SELECT c.wa_id, c.name FROM contacts c JOIN contact_labels cl ON cl.contact_id = c.id WHERE cl.label_id = ?', [(int) $request->input('label_id')]);
        } else {
            foreach ((array) $request->input('contacts', []) as $c) {
                $wa = preg_replace('/\D/', '', $c['wa_id'] ?? $c['number'] ?? '');
                if ($wa) $rows[] = (object) ['wa_id' => $wa, 'name' => trim($c['name'] ?? '') ?: null];
            }
        }

        $insert = [];
        foreach ($rows as $r) {
            $wa = preg_replace('/\D/', '', is_object($r) ? ($r->wa_id ?? '') : ($r['wa_id'] ?? ''));
            if (!$wa) continue;
            $insert[] = ['phonebook_id' => $pbId, 'wa_id' => $wa, 'name' => is_object($r) ? ($r->name ?? null) : ($r['name'] ?? null)];
        }
        $added = $insert ? DB::table('phonebook_contacts')->insertOrIgnore($insert) : 0;
        return response()->json(['ok' => true, 'added' => $added]);
    }

    protected function save(Request $request)
    {
        $id   = (int) $request->input('id');
        $name = trim((string) $request->input('name'));
        $desc = trim((string) $request->input('description'));
        if ($name === '') return response()->json(['ok' => false, 'error' => 'Ponle un nombre a la agenda'], 400);
        if ($id) {
            DB::table('phonebooks')->where('id', $id)->update(['name' => $name, 'description' => $desc, 'updated_at' => now()]);
            return response()->json(['ok' => true, 'id' => $id]);
        }
        $id = DB::table('phonebooks')->insertGetId(['name' => $name, 'description' => $desc, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['ok' => true, 'id' => $id]);
    }

    protected function detail(int $id)
    {
        $pb = DB::selectOne('SELECT * FROM phonebooks WHERE id = ?', [$id]);
        if (!$pb) return response()->json(['ok' => false, 'error' => 'No encontrada'], 404);
        $pb->contacts = DB::select('SELECT id, wa_id, name, created_at FROM phonebook_contacts WHERE phonebook_id = ? ORDER BY id DESC', [$id]);
        return response()->json(['ok' => true, 'phonebook' => $pb]);
    }

    protected function delete(Request $request)
    {
        if ($request->query('contact_id')) {
            DB::table('phonebook_contacts')->where('id', (int) $request->query('contact_id'))->delete();
            return response()->json(['ok' => true]);
        }
        $id = (int) $request->query('id', 0);
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
        DB::table('phonebooks')->where('id', $id)->delete();
        DB::table('phonebook_contacts')->where('phonebook_id', $id)->delete();
        return response()->json(['ok' => true]);
    }
}
