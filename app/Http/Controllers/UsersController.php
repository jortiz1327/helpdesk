<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Portado de api/users.php — gestión de usuarios/agentes.
 * Ahora el rol se gestiona con RBAC (spatie), no con la antigua columna `role`.
 * Exige el permiso users.manage (ver routes/api.php).
 */
class UsersController extends Controller
{
    public function handle(Request $request)
    {
        $me = $request->user();

        if ($request->isMethod('get')) {
            // Etiquetas de rol desde la BD (los roles son editables; config es solo semilla)
            $labels = Role::pluck('label', 'name')->all();

            // Categorías de cada usuario, en una sola consulta (evita N+1)
            $userCats = DB::table('user_ticket_categories')->get()
                ->groupBy('user_id')->map(fn ($rows) => $rows->pluck('category_id')->map('intval')->all());

            $users = User::with('roles')->orderBy('id')->get()->map(function ($u) use ($labels, $userCats) {
                $role = $u->getRoleNames()->first();
                return [
                    'id'           => (int) $u->id,
                    'name'         => $u->name,
                    'email'        => $u->email,
                    'role'         => $role,
                    'role_label'   => $role ? ($labels[$role] ?: $role) : '—',
                    'category_ids' => $userCats[$u->id] ?? [],
                    'created_at'   => $u->created_at,
                ];
            });

            return response()->json([
                'ok'         => true,
                'users'      => $users,
                // Catálogo de categorías (para asignar áreas al agente)
                'categories' => DB::table('ticket_categories')->where('active', 1)->orderBy('position')->get(['id', 'name', 'color']),
            ]);
        }

        if ($request->isMethod('post')) {
            $id    = (int) $request->input("id");
            $name  = trim((string) $request->input('name'));
            $email = trim((string) $request->input('email'));
            $pass  = (string) $request->input('password');
            $role  = (string) $request->input('role', config('rbac.default_role'));

            // El email es el identificador de acceso: obligatorio y único.
            if ($email === '') {
                return response()->json(['ok' => false, 'error' => 'El email es obligatorio (es con lo que se inicia sesión)'], 400);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['ok' => false, 'error' => 'El email no es válido'], 400);
            }
            if (User::where('email', $email)->where('id', '<>', $id)->exists()) {
                return response()->json(['ok' => false, 'error' => 'Ese email ya está en uso'], 400);
            }
            if (!Role::where('name', $role)->exists()) {
                return response()->json(['ok' => false, 'error' => 'Rol no válido'], 400);
            }

            if ($id) {
                $target = User::find($id);
                if (!$target) return response()->json(['ok' => false, 'error' => 'Usuario no encontrado'], 404);

                // No dejar la plataforma sin ningún superadministrador
                if ($target->isSuperAdmin() && $role !== config('rbac.super_role') && $this->superCount() <= 1) {
                    return response()->json(['ok' => false, 'error' => 'Debe quedar al menos un superadministrador'], 400);
                }
                if ($pass !== '' && strlen($pass) < 6) {
                    return response()->json(['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres'], 400);
                }

                $target->name = $name ?: null;
                $target->email = $email;
                if ($pass !== '') $target->password = $pass; // el cast 'hashed' lo cifra
                $target->save();
                $target->syncRoles([$role]);
                $this->syncCategories($target->id, $request->input('category_ids', []));

                return response()->json(['ok' => true, 'id' => $id]);
            }

            if (strlen($pass) < 6) {
                return response()->json(['ok' => false, 'error' => 'La contraseña debe tener al menos 6 caracteres'], 400);
            }

            $new = User::create([
                'password' => Hash::make($pass),
                'name'     => $name ?: null,
                'email'    => $email,
            ]);
            $new->syncRoles([$role]);
            $this->syncCategories($new->id, $request->input('category_ids', []));

            return response()->json(['ok' => true, 'id' => (int) $new->id]);
        }

        if ($request->isMethod('delete')) {
            $id = (int) $request->query('id', 0);
            if (!$id) return response()->json(['ok' => false, 'error' => 'Falta id'], 400);
            if ($id === (int) $me->id) {
                return response()->json(['ok' => false, 'error' => 'No puedes eliminar tu propia cuenta'], 400);
            }

            $target = User::find($id);
            if (!$target) return response()->json(['ok' => false, 'error' => 'Usuario no encontrado'], 404);
            if ($target->isSuperAdmin() && $this->superCount() <= 1) {
                return response()->json(['ok' => false, 'error' => 'Debe quedar al menos un superadministrador'], 400);
            }

            $target->delete();
            DB::table('contacts')->where('assigned_to', $id)->update(['assigned_to' => null]);
            return response()->json(['ok' => true]);
        }

        return response()->json(['ok' => false, 'error' => 'Método no permitido'], 405);
    }

    /** Reemplaza las categorías (áreas) de un usuario por las indicadas. */
    protected function syncCategories(int $userId, $categoryIds): void
    {
        $ids = array_values(array_unique(array_map('intval', (array) $categoryIds)));
        // Solo categorías que existan de verdad
        if ($ids) {
            $valid = DB::table('ticket_categories')->whereIn('id', $ids)->pluck('id')->all();
            $ids = array_values(array_intersect($ids, $valid));
        }

        DB::table('user_ticket_categories')->where('user_id', $userId)->delete();
        if ($ids) {
            DB::table('user_ticket_categories')->insert(
                array_map(fn ($cid) => ['user_id' => $userId, 'category_id' => $cid], $ids)
            );
        }
    }

    /** Cuántos superadministradores quedan (para no quedarnos sin ninguno). */
    protected function superCount(): int
    {
        return Role::where('name', config('rbac.super_role'))->first()?->users()->count() ?? 0;
    }
}
