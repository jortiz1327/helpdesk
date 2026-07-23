<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Portado de api/auth.php — dispatch por ?action=. Ruta pública. */
class AuthController extends Controller
{
    public function handle(Request $request)
    {
        $action = $request->query('action', '');
        $post   = $request->isMethod('post');

        if ($action === 'me') {
            return $this->me($request);
        }
        if ($action === 'login' && $post) {
            return $this->login($request);
        }
        if ($action === 'logout') {
            return response()->json(['ok' => true]);
        }
        if ($action === 'change' && $post) {
            return $this->change($request);
        }
        return response()->json(['error' => 'Acción no válida'], 400);
    }

    protected function me(Request $request)
    {
        $token = $request->header('X-App-Token') ?: $request->bearerToken() ?: $request->query('token');
        $user  = TokenService::verify($token);
        return response()->json([
            'authenticated' => (bool) $user,
            'user'          => $user ? $this->pub($user) : null,
        ]);
    }

    /** El acceso es por EMAIL (ya no existe el nombre de usuario). */
    protected function login(Request $request)
    {
        $email    = trim((string) $request->input('email'));
        $password = (string) $request->input('password');

        if ($email === '' || $password === '') {
            return response()->json(['ok' => false, 'error' => 'Introduce tu email y contraseña'], 400);
        }

        /*
         * RATE LIMITING contra la fuerza bruta. Dos contadores: por cuenta+IP (probar
         * contraseñas de UNA cuenta) y por IP (probar muchas cuentas). Solo cuentan los
         * intentos FALLIDOS; un login correcto los limpia. Persiste en la caché (BD).
         */
        // Los límites y el mensaje se configuran en «Configuración de soporte → Seguridad».
        $maxUser = max(1, (int) Setting::get('login_max_user', '7'));
        $maxIp   = max(1, (int) Setting::get('login_max_ip', '25'));
        $decay   = max(1, (int) Setting::get('login_lock_minutes', '5')) * 60;

        $ip      = $request->ip();
        $keyUser = 'login:' . Str::lower($email) . '|' . $ip;   // brute a una cuenta
        $keyIp   = 'login-ip:' . $ip;                            // spraying desde una IP
        if (RateLimiter::tooManyAttempts($keyUser, $maxUser) || RateLimiter::tooManyAttempts($keyIp, $maxIp)) {
            $secs = max(RateLimiter::availableIn($keyUser), RateLimiter::availableIn($keyIp));
            $msg  = trim((string) Setting::get('login_lock_message', '')) ?: 'Demasiados intentos. Inténtalo de nuevo en :segundos s.';
            return response()->json(['ok' => false, 'error' => str_replace(':segundos', (string) $secs, $msg)], 429);
        }

        $u = User::where('email', $email)->first();

        // Mismo mensaje exista o no el email: no revelamos qué correos están dados de alta.
        if (!$u || !Hash::check($password, $u->password)) {
            RateLimiter::hit($keyUser, $decay);
            RateLimiter::hit($keyIp, $decay);
            return response()->json(['ok' => false, 'error' => 'Email o contraseña incorrectos'], 401);
        }

        // Login correcto: se limpian los contadores de ese usuario/IP.
        RateLimiter::clear($keyUser);
        RateLimiter::clear($keyIp);
        return response()->json([
            'ok'    => true,
            'token' => TokenService::make($u),
            'user'  => $this->pub($u),
        ]);
    }

    protected function change(Request $request)
    {
        $token = $request->header('X-App-Token') ?: $request->bearerToken() ?: $request->query('token');
        $me    = TokenService::verify($token);
        if (!$me) {
            return response()->json(['error' => 'No autenticado', 'authenticated' => false], 401);
        }

        $current  = (string) $request->input('current');
        $newPass  = (string) $request->input('new_password');
        $newEmail = trim((string) $request->input('email'));

        if (!Hash::check($current, $me->password)) {
            return response()->json(['ok' => false, 'error' => 'La contraseña actual no es correcta'], 400);
        }
        if ($newEmail !== '' && $newEmail !== $me->email) {
            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                return response()->json(['ok' => false, 'error' => 'El email no es válido'], 400);
            }
            if (User::where('email', $newEmail)->where('id', '<>', $me->id)->exists()) {
                return response()->json(['ok' => false, 'error' => 'Ese email ya está en uso'], 400);
            }
            $me->email = $newEmail;
        }
        if ($newPass !== '') {
            if (strlen($newPass) < 6) {
                return response()->json(['ok' => false, 'error' => 'La nueva contraseña debe tener al menos 6 caracteres'], 400);
            }
            $me->password = Hash::make($newPass);
        }
        $me->save();

        return response()->json(['ok' => true, 'token' => TokenService::make($me)]);
    }

    /**
     * Datos públicos del usuario que consume el frontend.
     * Incluye sus PERMISOS y MÓDULOS: con ellos la SPA decide qué pintar.
     * (El backend valida igualmente cada ruta; el frontend solo oculta.)
     */
    protected function pub(User $u): array
    {
        $role = $u->roleName();

        return [
            'id'          => (int) $u->id,
            'name'        => $u->name,
            'email'       => $u->email,
            'role'        => $role,
            'role_label'  => $role ? (config("rbac.roles.$role.label") ?? $role) : null,
            'is_super'    => $u->isSuperAdmin(),
            'permissions' => $u->permissionNames(),
            'modules'     => $u->moduleNames(),
        ];
    }
}
