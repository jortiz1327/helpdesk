<?php

namespace App\Http\Controllers;

use App\Models\EmailBan;
use Illuminate\Http\Request;

/**
 * Lista de correos BLOQUEADOS (banlist). Vive en «Configuración de soporte».
 * Requiere support.config. CRUD sencillo: listar, guardar (crear/editar), borrar.
 */
class EmailBansController extends Controller
{
    public function handle(Request $request)
    {
        return match ($request->query('action', 'list')) {
            'save'   => $this->save($request),
            'delete' => $this->delete($request),
            default  => $this->list(),
        };
    }

    protected function list()
    {
        $bans = EmailBan::orderByDesc('updated_at')->get(['id', 'email', 'active', 'notes', 'updated_at']);
        return response()->json(['bans' => $bans]);
    }

    protected function save(Request $request)
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        if ($email === '') {
            return response()->json(['ok' => false, 'error' => 'La dirección es obligatoria'], 400);
        }
        // Se admite dirección completa (con @) o un dominio (para bloquearlo entero).
        $ok = filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/^@?[a-z0-9.-]+\.[a-z]{2,}$/i', $email);
        if (!$ok) {
            return response()->json(['ok' => false, 'error' => 'Escribe un correo válido o un dominio (p. ej. @spam.com)'], 400);
        }

        $id   = (int) $request->input('id');
        $data = [
            'email'  => mb_substr($email, 0, 190),
            'active' => filter_var($request->input('active', true), FILTER_VALIDATE_BOOLEAN),
            'notes'  => trim((string) $request->input('notes')) ?: null,
        ];

        // Unicidad del correo (evita duplicados al crear o al renombrar).
        $dup = EmailBan::where('email', $data['email'])->when($id, fn ($q) => $q->where('id', '!=', $id))->exists();
        if ($dup) {
            return response()->json(['ok' => false, 'error' => 'Esa dirección ya está en la lista'], 409);
        }

        $id ? EmailBan::where('id', $id)->update($data) : EmailBan::create($data);

        return response()->json(['ok' => true]);
    }

    protected function delete(Request $request)
    {
        EmailBan::where('id', (int) $request->input('id'))->delete();
        return response()->json(['ok' => true]);
    }
}
