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

    /**
     * Genera un token firmado para un usuario. Por defecto, el de SESIÓN (30 días, va en
     * la cabecera). Con $scope='media', uno corto y limitado para las URL de descargas.
     */
    public static function make(User $u, ?int $ttl = null, string $scope = 'full'): string
    {
        $datos = [
            'uid'   => (int) $u->id,
            'email' => $u->email,
            'name'  => $u->name ?? '',
            'tv'    => (int) ($u->token_version ?? 1),   // versión: si sube, este token caduca
            'exp'   => time() + ($ttl ?? 60 * 60 * 24 * 30),
        ];
        if ($scope !== 'full') $datos['sc'] = $scope;
        $payload = self::b64url(json_encode($datos));
        $sig = self::b64url(hash_hmac('sha256', $payload, self::secret(), true));
        return $payload . '.' . $sig;
    }

    /**
     * Token de MEDIOS: 24 h y solo para descargar (adjuntos, medios, CSV). Es el que va en
     * las URL (?token=): las URL acaban en logs e historial, así que ahí no va el de sesión.
     */
    public static function mediaToken(User $u): string
    {
        return self::make($u, 60 * 60 * 24, 'media');
    }

    /** Devuelve el User del token válido (fresco de BD para el rol), o null. */
    public static function verify(?string $token, string $scope = 'full'): ?User
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
        // Ámbito: un token de medios no sirve como sesión, ni al revés.
        if (($data['sc'] ?? 'full') !== $scope) {
            return null;
        }
        $u = User::find((int) ($data['uid'] ?? 0));
        // Cuenta deshabilitada (ex-empleado): fuera, aunque el token sea válido. null = activa.
        if (!$u || $u->deshabilitado()) {
            return null;
        }
        // Revocación: el token debe llevar la versión ACTUAL del usuario. Al cambiar la
        // contraseña se sube token_version y los tokens anteriores (con la versión vieja,
        // o sin ella) dejan de valer.
        if ((int) ($data['tv'] ?? 0) !== (int) ($u->token_version ?? 1)) {
            return null;
        }
        return $u;
    }
}
