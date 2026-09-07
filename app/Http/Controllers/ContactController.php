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

        // Alta de contactos a mano (no necesitan contact_id). El resto (save/labels) sí.
        if ($action === 'create' && $request->isMethod('post')) return $this->create($request);
        if ($action === 'bulk'   && $request->isMethod('post')) return $this->bulk($request);
        if ($action === 'import' && $request->isMethod('post')) return $this->import($request);

        $id = (int) $request->input('contact_id', 0);
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta contact_id'], 400);

        if ($action === 'save' && $request->isMethod('post')) {
            $data = $request->all();
            $upd  = [];

            if (array_key_exists('name', $data)) $upd['name'] = trim((string) $data['name']) ?: null;
            if (array_key_exists('note', $data)) $upd['note'] = $data['note'];

            // Campos de ficha comercial.
            foreach (['empresa', 'tienda', 'cargo', 'provincia', 'comentarios'] as $campo) {
                if (array_key_exists($campo, $data)) $upd[$campo] = trim((string) $data[$campo]) ?: null;
            }

            // Sede (organización): se guarda si existe; vacío = sin sede.
            if (array_key_exists('sede_id', $data)) {
                $sedeId = (int) $data['sede_id'];
                $upd['sede_id'] = ($sedeId && DB::table('sedes')->where('id', $sedeId)->exists()) ? $sedeId : null;
            }

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
                // Evita DUPLICAR el prefijo de país: si el número ya empieza por el
                // código (p. ej. un contacto de WhatsApp cuyo wa_id ya es país+número),
                // no se antepone otra vez → nada de «3434641510110».
                $wa = $ph === '' ? null : (($cc !== '' && str_starts_with($ph, $cc)) ? $ph : $cc . $ph);

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

    /** Alta de UN contacto (correo y/o teléfono). Dedup por teléfono o correo. */
    protected function create(Request $r)
    {
        $name  = trim((string) $r->input('name')) ?: null;
        $email = mb_strtolower(trim((string) $r->input('email')));
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'El correo no es válido'], 400);
        }
        $cc = preg_replace('/\D+/', '', (string) $r->input('country_code', ''));
        $ph = preg_replace('/\D+/', '', (string) $r->input('phone', ''));
        $wa = $ph === '' ? null : (($cc !== '' && str_starts_with($ph, $cc)) ? $ph : $cc . $ph);
        if ($wa !== null && strlen($wa) > 20) {
            return response()->json(['ok' => false, 'error' => 'El teléfono es demasiado largo'], 400);
        }
        if (!$email && !$wa) {
            return response()->json(['ok' => false, 'error' => 'Indica al menos un correo o un teléfono'], 400);
        }

        // No duplicar: si ya existe por teléfono o correo, se avisa (y se devuelve su id).
        $existe = null;
        if ($wa) $existe = DB::table('contacts')->where('wa_id', $wa)->first(['id']);
        if (!$existe && $email !== '') $existe = DB::table('contacts')->where('email', $email)->first(['id']);
        if ($existe) {
            return response()->json(['ok' => false, 'error' => 'Ya existe un contacto con ese teléfono o correo', 'id' => (int) $existe->id], 409);
        }

        $id = DB::table('contacts')->insertGetId([
            'name'         => $name,
            'email'        => $email ?: null,
            'wa_id'        => $wa,
            'country_code' => $cc ?: null,
            'empresa'      => trim((string) $r->input('empresa')) ?: null,
            'tienda'       => trim((string) $r->input('tienda')) ?: null,
            'cargo'        => trim((string) $r->input('cargo')) ?: null,
            'provincia'    => trim((string) $r->input('provincia')) ?: null,
            'comentarios'  => trim((string) $r->input('comentarios')) ?: null,
            'note'         => '[añadido a mano]',
            'created_at'   => now(),
        ]);
        return response()->json(['ok' => true, 'id' => $id]);
    }

    /**
     * Alta MASIVA. `type`: 'whatsapp' (número, nombre) o 'email' (email;nombre).
     * Una entrada por línea. Salta las inválidas y las que ya existen (dedup).
     */
    protected function bulk(Request $r)
    {
        $tipo   = $r->input('type') === 'email' ? 'email' : 'whatsapp';
        $lineas = preg_split('/\r\n|\r|\n/', (string) $r->input('text', ''));
        $inval  = 0;
        $cand   = [];   // clave (wa_id|email) => nombre (el último gana)

        foreach ($lineas as $ln) {
            $ln = trim($ln);
            if ($ln === '') continue;
            $partes = preg_split('/[;,]/', $ln, 2);
            $name   = trim($partes[1] ?? '') ?: null;
            if ($tipo === 'email') {
                $email = mb_strtolower(trim($partes[0] ?? ''));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) { $inval++; continue; }
                $cand[$email] = $name;
            } else {
                $wa = preg_replace('/\D+/', '', $partes[0] ?? '');
                if (strlen($wa) < 7 || strlen($wa) > 20) { $inval++; continue; }
                $cand[$wa] = $name;
            }
        }

        $col    = $tipo === 'email' ? 'email' : 'wa_id';
        $claves = array_map('strval', array_keys($cand));
        $dup    = 0;
        $rows   = [];
        if ($claves) {
            $ya    = DB::table('contacts')->whereIn($col, $claves)->pluck($col)->all();
            $yaSet = array_flip(array_map('strval', $ya));
            foreach ($cand as $clave => $name) {
                if (isset($yaSet[(string) $clave])) { $dup++; continue; }
                $rows[] = [
                    $col         => (string) $clave,
                    'name'       => $name,
                    'note'       => '[añadido a mano]',
                    'created_at' => now(),
                ];
            }
            foreach (array_chunk($rows, 500) as $lote) DB::table('contacts')->insert($lote);
        }

        return response()->json(['ok' => true, 'added' => count($rows), 'dup' => $dup, 'invalid' => $inval]);
    }

    /**
     * Importa contactos desde un Excel/CSV. Columnas por NOMBRE de cabecera
     * (Empresa, Tienda, Email, Nombre, Apellido, Cargo, Teléfono, Comentarios,
     * Provincia, Sector). La columna «Sector» se convierte en ETIQUETA (se crea si
     * no existe) y se asigna. Dedup por teléfono/correo: reutiliza (rellena huecos),
     * no duplica. El teléfono sin prefijo se asume de España (+34).
     */
    protected function import(Request $r)
    {
        $file = $r->file('file');
        if (!$file) return response()->json(['ok' => false, 'error' => 'No se recibió ningún archivo'], 400);
        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['csv', 'xlsx', 'xls'], true)) {
            return response()->json(['ok' => false, 'error' => 'Formato no válido: sube un .xlsx o un .csv'], 400);
        }

        try {
            if ($ext === 'csv') {
                $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
                $reader->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::guessEncoding($file->getRealPath()));
            } else {
                $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($ext === 'xls' ? 'Xls' : 'Xlsx');
            }
            $reader->setReadDataOnly(true);
            $rows = $reader->load($file->getRealPath())->getActiveSheet()->toArray(null, true, false, false);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'No se pudo leer el archivo: ' . $e->getMessage()], 400);
        }

        if (!$rows || count($rows) < 2) {
            return response()->json(['ok' => false, 'error' => 'El archivo está vacío o solo tiene la cabecera'], 400);
        }
        if (count($rows) > 20001) {
            return response()->json(['ok' => false, 'error' => 'Demasiadas filas (máximo 20.000). Divídelo en varios archivos.'], 400);
        }

        // Normaliza cabeceras (sin acentos/espacios/mayúsculas) y mapea columna → campo.
        $norm = fn ($s) => preg_replace('/[^a-z0-9]/', '', strtr(mb_strtolower(trim((string) $s)),
            ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n', 'ü' => 'u']));
        $alias = [
            'empresa' => 'empresa', 'tienda' => 'tienda',
            'email' => 'email', 'correo' => 'email', 'correoelectronico' => 'email',
            'nombre' => 'nombre', 'apellido' => 'apellido', 'apellidos' => 'apellido',
            'cargo' => 'cargo', 'puesto' => 'cargo',
            'telefono' => 'telefono', 'tel' => 'telefono', 'movil' => 'telefono', 'whatsapp' => 'telefono',
            'comentarios' => 'comentarios', 'comentario' => 'comentarios', 'observaciones' => 'comentarios', 'notas' => 'comentarios',
            'provincia' => 'provincia',
            'sector' => 'sector', 'etiqueta' => 'sector',
        ];
        $cab = array_shift($rows);
        $col = [];
        foreach ((array) $cab as $i => $h) {
            $k = $alias[$norm($h)] ?? null;
            if ($k && !isset($col[$k])) $col[$k] = $i;
        }
        if (!isset($col['email']) && !isset($col['telefono'])) {
            return response()->json(['ok' => false, 'error' => 'El archivo necesita al menos una columna «Email» o «Teléfono».'], 400);
        }
        $val = fn ($row, $campo) => isset($col[$campo]) ? trim((string) ($row[$col[$campo]] ?? '')) : '';

        $added = 0; $reused = 0; $invalid = 0; $tagsNew = 0;
        $labelCache = [];
        $colores = ['#12925a', '#124e86', '#b8722a', '#7c3aed', '#0ea5b7', '#d0503f', '#a0d911', '#e056fd', '#f59e0b', '#2dd4bf'];

        foreach ($rows as $row) {
            if (!is_array($row)) continue;

            $email = mb_strtolower($val($row, 'email'));
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $email = '';
            $ph = preg_replace('/\D+/', '', $val($row, 'telefono'));
            $wa = $ph === '' ? null : (str_starts_with($ph, '34') && strlen($ph) >= 11 ? $ph : (strlen($ph) <= 9 ? '34' . $ph : $ph));
            if ($wa !== null && (strlen($wa) < 7 || strlen($wa) > 20)) $wa = null;
            if ($email === '' && !$wa) { $invalid++; continue; }

            $nombre = trim($val($row, 'nombre') . ' ' . $val($row, 'apellido'));
            $datos = [
                'name'        => $nombre ?: null,
                'empresa'     => $val($row, 'empresa') ?: null,
                'tienda'      => $val($row, 'tienda') ?: null,
                'cargo'       => $val($row, 'cargo') ?: null,
                'provincia'   => $val($row, 'provincia') ?: null,
                'comentarios' => $val($row, 'comentarios') ?: null,
            ];

            // Dedup: por teléfono, luego por correo. Reutiliza rellenando solo huecos.
            $c = null;
            if ($wa) $c = DB::table('contacts')->where('wa_id', $wa)->first();
            if (!$c && $email !== '') $c = DB::table('contacts')->where('email', $email)->first();

            if ($c) {
                $upd = [];
                foreach ($datos as $k => $v) {
                    if ($v !== null && (($c->$k ?? null) === null || $c->$k === '')) $upd[$k] = $v;
                }
                if ($email !== '' && !($c->email ?? '')) $upd['email'] = $email;
                if ($wa && !($c->wa_id ?? '')) { $upd['wa_id'] = $wa; $upd['country_code'] = '34'; }
                if ($upd) DB::table('contacts')->where('id', $c->id)->update($upd);
                $cid = (int) $c->id;
                $reused++;
            } else {
                $cid = (int) DB::table('contacts')->insertGetId($datos + [
                    'email'        => $email ?: null,
                    'wa_id'        => $wa,
                    'country_code' => $wa ? '34' : null,
                    'note'         => '[importado de Excel/CSV]',
                    'created_at'   => now(),
                ]);
                $added++;
            }

            // Sector → etiqueta (crear si no existe) y asignar al contacto.
            $sector = $val($row, 'sector');
            if ($sector !== '') {
                $key = $norm($sector);
                if ($key !== '' && !isset($labelCache[$key])) {
                    $lab = DB::table('labels')->whereRaw('LOWER(name) = ?', [mb_strtolower($sector)])->first(['id']);
                    if ($lab) {
                        $labelCache[$key] = (int) $lab->id;
                    } else {
                        $pos = (int) DB::table('labels')->max('position') + 1;
                        $labelCache[$key] = (int) DB::table('labels')->insertGetId([
                            'name'  => mb_substr($sector, 0, 60),
                            'color' => $colores[$tagsNew % count($colores)],
                            'position' => $pos,
                        ]);
                        $tagsNew++;
                    }
                }
                if ($key !== '') DB::table('contact_labels')->insertOrIgnore(['contact_id' => $cid, 'label_id' => $labelCache[$key]]);
            }
        }

        return response()->json(['ok' => true, 'added' => $added, 'reused' => $reused, 'invalid' => $invalid, 'tags_new' => $tagsNew]);
    }
}
