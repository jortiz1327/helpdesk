<?php

namespace App\Services\Zkong;

use App\Models\ZkongServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Conexión con UN servidor ZKONG.
 *
 * Login: la contraseña se cifra con la clave pública RSA que da el propio servidor
 * (/user/getErpPublicKey) y se manda a /user/login con loginType 3. El token se guarda
 * cifrado en la fila del servidor y se reutiliza; si el servidor responde que caducó
 * (códigos 10006 / 10013) se vuelve a entrar y se repite la llamada UNA vez.
 *
 * A diferencia de la app de garantías: si el servidor va por https se verifica su
 * certificado, y cada llamada tiene un tiempo máximo, para que un servidor caído no
 * deje colgada la pantalla.
 */
class ZkongClient
{
    private const TOKEN_CADUCADO = [10006, 10013];

    private bool $reintentado = false;

    public function __construct(private ZkongServer $server)
    {
    }

    public function get(string $uri, array $query = []): array
    {
        return $this->call('GET', $uri, $query);
    }

    public function post(string $uri, array $body = []): array
    {
        return $this->call('POST', $uri, $body);
    }

    /**
     * Varias llamadas POST A LA VEZ (en paralelo). Devuelve, con la misma clave que cada
     * llamada, su respuesta o null si falló. No reintenta el login: se usa después de
     * una llamada normal, que ya dejó el token al día.
     *
     * @param  array<string, array{0: string, 1: array}>  $calls  clave => [uri, cuerpo]
     * @return array<string, ?array>
     */
    public function postMany(array $calls): array
    {
        if (! $calls) {
            return [];
        }
        if (! $this->server->token) {
            $this->login();
        }
        $base = rtrim($this->server->base_url, '/');
        $headers = ['Language' => 'en', 'Authorization' => $this->server->token];

        $res = Http::pool(function (Pool $pool) use ($calls, $base, $headers) {
            foreach ($calls as $key => [$uri, $body]) {
                $pool->as((string) $key)->withHeaders($headers)->acceptJson()->asJson()
                    ->connectTimeout(8)->timeout(25)->post($base . $uri, $body);
            }
        });

        $out = [];
        foreach ($calls as $key => $_) {
            $r = $res[(string) $key] ?? null;
            $json = $r instanceof Response ? $r->json() : null;
            $out[$key] = is_array($json) && ($json['success'] ?? false) ? $json : null;
        }
        return $out;
    }

    /** Entra en el servidor y guarda el token. Devuelve el comercio y la agencia de la cuenta. */
    public function login(): array
    {
        $key = $this->send('GET', '/user/getErpPublicKey', [], null)['data'] ?? null;
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('El servidor no devolvió su clave pública.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . trim($key) . "\n-----END PUBLIC KEY-----\n";
        if (! openssl_public_encrypt((string) $this->server->password, $cifrada, $pem)) {
            throw new RuntimeException('No se pudo cifrar la contraseña con la clave del servidor.');
        }

        $json = $this->send('POST', '/user/login', [
            'account'   => $this->server->username,
            'password'  => base64_encode($cifrada),
            'loginType' => 3,
        ], null);

        $token = $json['data']['token'] ?? null;
        if (! $token) {
            throw new RuntimeException('Usuario o contraseña incorrectos: ' . ($json['message'] ?? 'el servidor rechazó el acceso'));
        }

        // Sin tocar updated_at: es la marca de «se editó el servidor» que invalida su caché.
        $this->server->timestamps = false;
        $this->server->forceFill([
            'token'            => $token,
            'merchant_id'      => $json['data']['currentUser']['merchantId'] ?? null,
            'agency_id'        => $json['data']['currentUser']['agencyId'] ?? null,
            'token_updated_at' => now(),
        ])->save();
        $this->server->timestamps = true;

        return ['merchant_id' => $this->server->merchant_id, 'agency_id' => $this->server->agency_id];
    }

    private function call(string $method, string $uri, array $data): array
    {
        if (! $this->server->token) {
            $this->login();
        }
        $json = $this->send($method, $uri, $data, $this->server->token);

        if (! ($json['success'] ?? false) && in_array($json['code'] ?? null, self::TOKEN_CADUCADO, true) && ! $this->reintentado) {
            $this->reintentado = true;
            $this->login();
            $json = $this->send($method, $uri, $data, $this->server->token);
        }

        if (! ($json['success'] ?? false)) {
            throw new RuntimeException($json['message'] ?? 'El servidor devolvió un error.');
        }
        return $json;
    }

    private function send(string $method, string $uri, array $data, ?string $token): array
    {
        $headers = ['Language' => 'en'];
        if ($token) {
            $headers['Authorization'] = $token;   // ZKONG lo quiere tal cual, sin «Bearer»
        }
        $url = rtrim($this->server->base_url, '/') . $uri;

        try {
            $http = Http::withHeaders($headers)->acceptJson()->connectTimeout(8)->timeout(25);
            $res = $method === 'GET' ? $http->get($url, $data) : $http->asJson()->post($url, $data);
        } catch (ConnectionException $e) {
            throw new RuntimeException('No se pudo conectar con ' . $this->server->name . ' (sin respuesta: revisa la dirección y el puerto).');
        }

        $json = $res->json();
        if (! is_array($json)) {
            throw new RuntimeException('Respuesta inesperada de ' . $this->server->name . ' (HTTP ' . $res->status() . ').');
        }
        return $json;
    }
}
