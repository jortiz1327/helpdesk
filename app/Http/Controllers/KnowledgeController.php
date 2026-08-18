<?php

namespace App\Http\Controllers;

use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Documentos de la base de conocimiento de la IA (INTERNOS, no los ve el cliente).
 * El agente de IA se apoya en su texto para responder. Se puede SUBIR un fichero
 * (PDF / TXT / MD / CSV — se extrae su texto) o PEGAR texto directamente.
 *
 * Requiere support.config (encargados/superadmin), igual que las FAQs.
 */
class KnowledgeController extends Controller
{
    /** Tipos aceptados y su forma de extraer texto. */
    private const MAX_BYTES = 8 * 1024 * 1024;   // 8 MB

    public function handle(Request $request)
    {
        $action = $request->query('action', 'list');

        return match ($action) {
            'get'    => $this->getOne($request),    // leer un documento (para ver/editar)
            'save'   => $this->save($request),      // pegar texto o editar título/estado
            'upload' => $this->upload($request),    // subir fichero
            'delete' => $this->delete($request),
            default  => $this->list(),
        };
    }

    private function list()
    {
        $docs = DB::table('knowledge_docs as k')
            ->leftJoin('users as u', 'u.id', '=', 'k.created_by')
            ->orderByDesc('k.id')
            ->get(['k.id', 'k.title', 'k.filename', 'k.mime', 'k.size', 'k.active', 'k.created_at',
                   'u.name as author', DB::raw('CHAR_LENGTH(k.content) as chars')]);

        return response()->json(['ok' => true, 'docs' => $docs]);
    }

    /** Devuelve un documento completo (con su texto) para verlo o editarlo. */
    private function getOne(Request $request)
    {
        $id = (int) $request->query('id', $request->input('id', 0));
        $d = DB::table('knowledge_docs')->where('id', $id)->first(['id', 'title', 'content', 'active', 'filename']);
        if (!$d) return response()->json(['ok' => false, 'error' => 'Documento no encontrado'], 404);
        return response()->json(['ok' => true, 'doc' => $d]);
    }

    /** Guarda un documento de TEXTO pegado, o edita el título/estado de uno existente. */
    private function save(Request $request)
    {
        $id    = (int) $request->input('id', 0);
        $title = trim((string) $request->input('title', ''));

        if ($id) {
            $upd = [];
            if ($title !== '') $upd['title'] = mb_substr($title, 0, 200);
            if ($request->has('active')) $upd['active'] = filter_var($request->input('active'), FILTER_VALIDATE_BOOLEAN) ? 1 : 0;
            if ($request->has('content')) $upd['content'] = (string) $request->input('content');
            if ($upd) DB::table('knowledge_docs')->where('id', $id)->update($upd);
            return response()->json(['ok' => true, 'id' => $id]);
        }

        $content = trim((string) $request->input('content', ''));
        if ($title === '' || $content === '') {
            return response()->json(['ok' => false, 'error' => 'Ponle un título y el texto del documento'], 400);
        }

        $newId = DB::table('knowledge_docs')->insertGetId([
            'title'      => mb_substr($title, 0, 200),
            'content'    => $content,
            'active'     => 1,
            'created_by' => $this->userId($request),
            'created_at' => now(),
        ]);
        return response()->json(['ok' => true, 'id' => $newId]);
    }

    /** Sube un fichero (PDF/TXT/MD/CSV), extrae su texto y lo guarda. */
    private function upload(Request $request)
    {
        $file = $request->file('file');
        if (!$file || !$file->isValid()) {
            return response()->json(['ok' => false, 'error' => 'No llegó ningún fichero'], 400);
        }
        if ($file->getSize() > self::MAX_BYTES) {
            return response()->json(['ok' => false, 'error' => 'El fichero supera el límite de 8 MB'], 400);
        }

        $ext  = strtolower($file->getClientOriginalExtension());
        $mime = (string) $file->getMimeType();

        try {
            $texto = match ($ext) {
                'pdf'                 => $this->desdePdf($file->getRealPath()),
                'txt', 'md', 'csv', 'log' => $this->limpiar((string) file_get_contents($file->getRealPath())),
                default               => null,
            };
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'error' => 'No se pudo leer el fichero: ' . $e->getMessage()], 422);
        }

        if ($texto === null) {
            return response()->json(['ok' => false, 'error' => 'Formato no admitido. Usa PDF, TXT, MD o CSV (o pega el texto).'], 422);
        }
        if (trim($texto) === '') {
            return response()->json(['ok' => false, 'error' => 'El documento no tiene texto legible (¿es un PDF escaneado como imagen?). Pega el texto a mano.'], 422);
        }

        $title = trim((string) $request->input('title', '')) ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);

        $newId = DB::table('knowledge_docs')->insertGetId([
            'title'      => mb_substr($title, 0, 200),
            'filename'   => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime'       => mb_substr($mime, 0, 100),
            'size'       => (int) $file->getSize(),
            'content'    => mb_substr($texto, 0, 200000),   // tope defensivo
            'active'     => 1,
            'created_by' => $this->userId($request),
            'created_at' => now(),
        ]);

        return response()->json(['ok' => true, 'id' => $newId, 'chars' => mb_strlen($texto)]);
    }

    private function delete(Request $request)
    {
        $id = (int) $request->input('id', $request->query('id', 0));
        if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
        DB::table('knowledge_docs')->where('id', $id)->delete();
        return response()->json(['ok' => true]);
    }

    // --------------------------------------------------------------- utilidades

    private function desdePdf(string $path): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($path);
        return $this->limpiar($pdf->getText());
    }

    /** Normaliza el texto extraído (colapsa espacios y líneas en blanco de más). */
    private function limpiar(string $s): string
    {
        $s = preg_replace('/[ \t]+/', ' ', $s);
        $s = preg_replace('/\n{3,}/', "\n\n", (string) $s);
        return trim((string) $s);
    }

    private function userId(Request $request): ?int
    {
        $u = TokenService::verify($request->header('X-App-Token') ?: $request->bearerToken());
        return $u?->id;
    }
}
