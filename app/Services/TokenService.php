<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\User;

/**
 * Autenticación por token firmado (HMAC-SHA256), sin sesiones ni cookies.
 * Portado de includes/auth.php de app-whatsapp. El navegador guarda el token
 * y lo envía en la cabecera X-App-Token (o Bearer, o ?token= para medios).
 */
class TokenService
{
    /** Secreto único por instalación (se crea y guarda la primera vez). */
    public static function secret(): string
    {
        $s = Setting::get('auth_secret', '');
        if ($s === '' || $s === null) {
            $s = bin2hex(random_bytes(32));
            Setting::put('auth_secret', $s);
        }
        return $s;
    }

    protected static function b64url(string $d): string
    {
        return rtrim(strtr(base64_encode($d), '+/', '-_'), '=');
    }

    protected static function b64urlDec(string $d): string
    {
        return base64_decode(strtr($d, '-_', '+/'));
    }

    /** Genera un token firmado para un usuario (válido 30 días). */
    public static function make(User $u): string
    {
        $payload = self::b64url(json_encode([
            'uid'   => (int) $u->id,
            'email' => $u->email,
            'name'  => $u->name ?? '',
            'exp'   => time() + 60 * 60 * 24 * 30,
        ]));
        $sig = self::b64url(hash_hmac('sha256', $payload, self::secret(), true));
        return $payload . '.' . $sig;
    }

    /** Devuelve el User del token válido (fresco de BD para el rol), o null. */
    public static function verify(?string $token): ?User
    {
        $token = trim((string) $token);
        if ($token === '' || substr_count($token, '.') !== 1) {
            return null;
        }
        [$payload, $sig] = explode('.', $token);
        $expected = self::b64url(hash_hmac('sha256', $payload, self::secret(), true));
        if (!hash_equals($expected, $sig)) {
            return null;
        }
        $data = json_decode(self::b64urlDec($payload), true);
        if (!is_array($data) || (int) ($data['exp'] ?? 0) <= time()) {
            return null;
        }
        return User::find((int) ($data['uid'] ?? 0));
    }
}
