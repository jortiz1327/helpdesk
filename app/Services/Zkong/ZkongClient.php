<?php

namespace App\Services\Zkong;

use App\Models\ZkongServer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

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

    /**
     * $productos: entra con la cuenta que crea productos (product_*) en vez de la de
     * administrador. Cada cuenta tiene su token y su comercio; la clave del servidor es la misma.
     */
    public function __construct(private ZkongServer $server, private bool $productos = false)
    {
    }

    /** Nombre de la columna para la cuenta en uso: token → product_token, etc. */
    private function col(string $campo): string
    {
        return $this->productos ? "product_{$campo}" : $campo;
    }

    private function token(): ?string
    {
        return $this->server->{$this->col('token')};
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
        if (! $this->token()) {
            $this->login();
        }
        $base = rtrim($this->server->base_url, '/');
        $headers = ['Language' => 'en', 'Authorization' => $this->token()];

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
            throw new ZkongException('El servidor no devolvió su clave pública.');
        }
        // Clave fijada: la primera vez se apunta su huella y, si luego cambia, no se manda la
        // contraseña (por http alguien en medio podría haber puesto la suya).
        $huella = hash('sha256', preg_replace('/\s+/', '', $key));
        if ($this->server->public_key_sha256 && ! hash_equals($this->server->public_key_sha256, $huella)) {
            throw new ZkongException('La clave pública de ' . $this->server->name . ' no es la de siempre y no se le ha enviado la contraseña. '
                . 'Si el servidor la ha cambiado de verdad, vuelve a escribir su contraseña en Servidores ZKONG para aceptar la nueva.');
        }
        $usuario = (string) $this->server->{$this->col('username')};
        if ($usuario === '') {
            throw new ZkongException($this->server->name . ' no tiene ' . ($this->productos ? 'cuenta de productos' : 'usuario') . ' configurado.');
        }
        $pem = "-----BEGIN PUBLIC KEY-----\n" . trim($key) . "\n-----END PUBLIC KEY-----\n";
        if (! openssl_public_encrypt((string) $this->server->{$this->col('password')}, $cifrada, $pem)) {
            throw new ZkongException('No se pudo cifrar la contraseña con la clave del servidor.');
        }

        $json = $this->send('POST', '/user/login', [
            'account'   => $usuario,
            'password'  => base64_encode($cifrada),
            'loginType' => 3,
        ], null);

        $token = $json['data']['token'] ?? null;
        if (! $token) {
            throw new ZkongException('Usuario o contraseña incorrectos: ' . ($json['message'] ?? 'el servidor rechazó el acceso'));
        }

        // Sin tocar updated_at: es la marca de «se editó el servidor» que invalida su caché.
        $this->server->timestamps = false;
        $this->server->forceFill([
            $this->col('token')            => $token,
            $this->col('merchant_id')      => $json['data']['currentUser']['merchantId'] ?? null,
            $this->col('agency_id')        => $json['data']['currentUser']['agencyId'] ?? null,
            $this->col('token_updated_at') => now(),
            'public_key_sha256' => $huella,
        ])->save();
        $this->server->timestamps = true;

        return ['merchant_id' => $this->server->{$this->col('merchant_id')}, 'agency_id' => $this->server->{$this->col('agency_id')}];
    }

    private function call(string $method, string $uri, array $data): array
    {
        if (! $this->token()) {
            $this->login();
        }
        $json = $this->send($method, $uri, $data, $this->token());

        if (! ($json['success'] ?? false) && in_array($json['code'] ?? null, self::TOKEN_CADUCADO, true) && ! $this->reintentado) {
            $this->reintentado = true;
            $this->login();
            $json = $this->send($method, $uri, $data, $this->token());
        }

        if (! ($json['success'] ?? false)) {
            throw new ZkongException($json['message'] ?? 'El servidor devolvió un error.');
        }
        return $json;
    }

    /**
     * Los servidores ZKONG son de internet: una dirección que es (o resuelve a) loopback,
     * red privada, link-local (metadatos de la nube) o reservada no se admite. Si el nombre
     * no resuelve, se deja: la conexión fallará sola.
     */
    public static function hostPermitido(string $url): bool
    {
        $host = trim((string) parse_url($url, PHP_URL_HOST), '[]');
        if ($host === '' || strtolower($host) === 'localhost') return false;
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        foreach ($ips as $ip) {
            if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
        }
        return true;
    }

    private function send(string $method, string $uri, array $data, ?string $token): array
    {
        $headers = ['Language' => 'en'];
        if ($token) {
            $headers['Authorization'] = $token;   // ZKONG lo quiere tal cual, sin «Bearer»
        }
        $url = rtrim($this->server->base_url, '/') . $uri;
        if (! self::hostPermitido($url)) {
            throw new ZkongException('La dirección de ' . $this->server->name . ' apunta a una red interna: no se usa.');
        }

        try {
            // Sin seguir redirecciones: la contraseña y el token solo van a la dirección escrita.
            $http = Http::withHeaders($headers)->acceptJson()->connectTimeout(8)->timeout(25)->withoutRedirecting();
            $res = $method === 'GET' ? $http->get($url, $data) : $http->asJson()->post($url, $data);
        } catch (ConnectionException $e) {
            throw new ZkongException('No se pudo conectar con ' . $this->server->name . ' (sin respuesta: revisa la dirección y el puerto).');
        }

        $json = $res->json();
        if (! is_array($json)) {
            throw new ZkongException('Respuesta inesperada de ' . $this->server->name . ' (HTTP ' . $res->status() . ').');
        }
        return $json;
    }
}
