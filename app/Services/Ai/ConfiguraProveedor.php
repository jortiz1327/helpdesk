<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\Response;

/**
 * Lo común a todos los proveedores: leer su bloque de config('ai.providers.<nombre>'),
 * exigir la clave y convertir un error HTTP en AiException sin filtrar el cuerpo
 * (puede llevar datos de la petición). `$config` permite inyectar otra config en tests.
 */
trait ConfiguraProveedor
{
    public function __construct(protected ?array $config = null)
    {
    }

    abstract public function name(): string;

    protected function cfg(string $k, mixed $default = null): mixed
    {
        return ($this->config ?? config('ai.providers.' . $this->name(), []))[$k] ?? $default;
    }

    protected function key(): string
    {
        return trim((string) $this->cfg('api_key'));
    }

    protected function model(): string
    {
        return (string) $this->cfg('model');
    }

    protected function url(string $path): string
    {
        return rtrim((string) $this->cfg('base_url'), '/') . $path;
    }

    protected function timeout(): int
    {
        return (int) config('ai.timeout', 30);
    }

    protected function maxTokens(): int
    {
        return (int) config('ai.max_tokens', 700);
    }

    public function configured(): bool
    {
        return $this->key() !== '';
    }

    protected function exigirClave(): void
    {
        if (! $this->configured()) {
            throw new AiException('La IA no está configurada (' . $this->name() . ')');
        }
    }

    protected function exigirOk(Response $r): void
    {
        if (! $r->successful()) {
            throw new AiException('La IA respondió con un error (' . $r->status() . ')');
        }
    }
}
