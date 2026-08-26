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

            // Estadísticas por agente, cada una en UNA consulta agrupada (no N+1).
            $abiertos = \App\Services\TicketService::OPEN_STATUSES;
            $asignados = DB::table('tickets')->whereIn('status', $abiertos)->where('channel', '!=', 'cron')
                ->whereNotNull('assigned_to')->select('assigned_to', DB::raw('COUNT(*) n'))
                ->groupBy('assigned_to')->pluck('n', 'assigned_to');
            $resueltos = DB::table('tickets')->whereNotNull('resolved_at')->where('resolved_at', '>=', now()->subDays(7))
                ->select('assigned_to', DB::raw('COUNT(*) n'))->groupBy('assigned_to')->pluck('n', 'assigned_to');
            // SLA vencido de sus tickets abiertos (vencimiento pasado, respuesta aún sin dar o resolución fuera de plazo).
            $vencidos = DB::table('tickets')->whereIn('status', $abiertos)->whereNotNull('assigned_to')
                ->where(fn ($q) => $q->where('sla_resolve_due_at', '<', now())
                    ->orWhere(fn ($w) => $w->whereNull('first_response_at')->where('sla_response_due_at', '<', now())))
                ->select('assigned_to', DB::raw('COUNT(*) n'))->groupBy('assigned_to')->pluck('n', 'assigned_to');
            // Última actividad registrada de cada usuario (para el estado «en línea»).
            $ultima = DB::table('activity_log')->whereNotNull('user_id')
                ->select('user_id', DB::raw('MAX(created_at) last'))->groupBy('user_id')->pluck('last', 'user_id');

            $users = User::with('roles')->orderBy('id')->get()
                ->map(function ($u) use ($labels, $userCats, $asignados, $resueltos, $vencidos, $ultima) {
                    $role = $u->getRoleNames()->first();
                    return [
                        'id'           => (int) $u->id,
                        'name'         => $u->name,
                        'email'        => $u->email,
                        'role'         => $role,
                        'role_label'   => $role ? ($labels[$role] ?: $role) : '—',
                        'active'       => (bool) $u->active,   // false = ya no está (ex-empleado / histórico)
                        'category_ids' => $userCats[$u->id] ?? [],
                        'view_all'     => $u->can('tickets.view_all'),
                        'notify_sla'      => (bool) $u->notify_sla,
                        'notify_assigned' => (bool) $u->notify_assigned,
                        'assigned'     => (int) ($asignados[$u->id] ?? 0),
                        'resolved_7d'  => (int) ($resueltos[$u->id] ?? 0),
                        'sla_late'     => (int) ($vencidos[$u->id] ?? 0),
                        'last_activity' => $ultima[$u->id] ?? null,
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
                if ($pass !== '' && strlen($pass) < 8) {
                    return response()->json(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'], 400);
                }

                $target->name = $name ?: null;
                $target->email = $email;
                $target->notify_sla = $request->boolean('notify_sla', true);
                $target->notify_assigned = $request->boolean('notify_assigned', true);
                if ($pass !== '') $target->password = $pass; // el cast 'hashed' lo cifra
                $target->save();
                $target->syncRoles([$role]);
                $this->syncCategories($target->id, $request->input('category_ids', []));

                return response()->json(['ok' => true, 'id' => $id]);
            }

            if (strlen($pass) < 8) {
                return response()->json(['ok' => false, 'error' => 'La contraseña debe tener al menos 8 caracteres'], 400);
            }

            $new = User::create([
                'password' => Hash::make($pass),
                'name'     => $name ?: null,
                'email'    => $email,
                'notify_sla'      => $request->boolean('notify_sla', true),
                'notify_assigned' => $request->boolean('notify_assigned', true),
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
