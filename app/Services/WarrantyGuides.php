<?php

namespace App\Services;

use App\Models\Warranty;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Protocolos y guías: documentación operativa de garantías (no la ayuda de la app). Cada
 * documento tiene un tipo, un idioma y un ámbito: general, unas categorías o unos clientes
 * (texto que contiene su nombre o proyecto). La ficha de cada garantía enseña los suyos.
 */
class WarrantyGuides
{
    public const KINDS = [
        'protocolos'  => 'Protocolos internos',
        'condiciones' => 'Condiciones de garantía',
        'tecnicas'    => 'Guías técnicas',
        'plantillas'  => 'Plantillas de comunicación',
        'clientes'    => 'Procedimientos por cliente',
        'operativa'   => 'Operativa diaria',
    ];
    public const LANGUAGES = ['es' => 'Español', 'en' => 'Inglés'];
    // Lo que se puede subir: documentos de oficina, PDF, imágenes y texto.
    public const EXT = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'txt', 'png', 'jpg', 'jpeg'];
    public const MAX_KB = 20480;

    public static function row(object $g): array
    {
        return [
            'id' => $g->id, 'title' => $g->title, 'description' => $g->description, 'kind' => $g->kind,
            'language' => $g->language, 'categories' => json_decode($g->categories ?? '[]', true) ?: [],
            'clients' => json_decode($g->clients ?? '[]', true) ?: [], 'version' => $g->version,
            'keywords' => $g->keywords, 'file_name' => $g->file_name, 'mime' => $g->mime, 'size' => (int) $g->size,
            'active' => (bool) $g->active, 'updated_at' => (string) $g->updated_at, 'updated_by_name' => $g->updated_by_name ?? null,
        ];
    }

    /** Todos (los inactivos solo para quien los gestiona), en el orden de la lista. */
    public static function all(bool $inactivos): array
    {
        return DB::table('warranty_guides as g')->leftJoin('users as u', 'u.id', '=', 'g.updated_by')
            ->when(! $inactivos, fn ($q) => $q->where('g.active', true))
            ->orderBy('g.sort')->orderBy('g.title')->get(['g.*', 'u.name as updated_by_name'])
            ->map(fn ($g) => self::row($g))->all();
    }

    /** Si es para esta garantía por su categoría o su cliente (los generales aparte). */
    public static function specificTo(array $g, Warranty $w): bool
    {
        $cat = mb_strtolower(trim((string) $w->category));
        if ($cat !== '' && collect($g['categories'])->contains(fn ($c) => mb_strtolower(trim($c)) === $cat)) return true;
        $quien = mb_strtolower($w->client_name . ' ' . $w->project_name);
        return collect($g['clients'])->contains(fn ($c) => ($c = mb_strtolower(trim($c))) !== '' && str_contains($quien, $c));
    }

    public static function isGeneral(array $g): bool
    {
        return ! $g['categories'] && ! $g['clients'];
    }

    /** La documentación de una garantía: primero la de su caso, luego la general. */
    public static function forWarranty(Warranty $w): array
    {
        $todos = self::all(false);
        $suyos = array_values(array_filter($todos, fn ($g) => self::specificTo($g, $w)));
        $generales = array_values(array_filter($todos, fn ($g) => self::isGeneral($g)));
        return ['specific' => $suyos, 'general' => $generales];
    }

    /** Valida lo del formulario. [datos, error]. */
    public static function clean(array $in, array $categorias): array
    {
        $titulo = mb_substr(trim((string) ($in['title'] ?? '')), 0, 160);
        if ($titulo === '') return [null, 'Escribe el título'];
        $kind = (string) ($in['kind'] ?? '');
        if (! isset(self::KINDS[$kind])) return [null, 'Elige el tipo de documento'];
        $lang = (string) ($in['language'] ?? 'es');
        if (! isset(self::LANGUAGES[$lang])) return [null, 'Idioma no válido'];
        $lista = fn ($v) => is_array($v) ? $v : (json_decode((string) $v, true) ?: []);
        $cats = collect($categorias)->mapWithKeys(fn ($n) => [mb_strtolower($n) => $n]);
        $lc = [];
        foreach ($lista($in['categories'] ?? []) as $c) {
            $n = $cats[mb_strtolower(trim((string) $c))] ?? null;
            if (! $n) return [null, "La categoría «{$c}» no existe"];
            $lc[$n] = $n;
        }
        $clientes = array_values(array_unique(array_filter(array_map(fn ($c) => mb_substr(trim((string) $c), 0, 60), $lista($in['clients'] ?? [])))));
        return [[
            'title' => $titulo,
            'description' => mb_substr(trim((string) ($in['description'] ?? '')), 0, 1000) ?: null,
            'kind' => $kind, 'language' => $lang,
            'categories' => json_encode(array_values($lc), JSON_UNESCAPED_UNICODE),
            'clients' => json_encode(array_slice($clientes, 0, 20), JSON_UNESCAPED_UNICODE),
            'version' => mb_substr(trim((string) ($in['version'] ?? '')), 0, 20) ?: null,
            'keywords' => mb_substr(trim((string) ($in['keywords'] ?? '')), 0, 500) ?: null,
            'active' => filter_var($in['active'] ?? true, FILTER_VALIDATE_BOOLEAN),
        ], null];
    }

    /** Guarda el archivo subido. [path, nombre, mime, tamaño] o el error en texto. */
    public static function store(UploadedFile $f): array|string
    {
        $ext = strtolower($f->getClientOriginalExtension());
        if (! in_array($ext, self::EXT, true)) return 'Ese tipo de archivo no se puede subir (PDF, Word, Excel, PowerPoint, imágenes o texto)';
        if ($f->getSize() > self::MAX_KB * 1024) return 'El archivo pasa de ' . (self::MAX_KB / 1024) . ' MB';
        $path = 'warranty_guides/' . Str::random(32) . '.' . $ext;
        Storage::disk('local')->put($path, file_get_contents($f->getRealPath()));
        $nombre = mb_substr(preg_replace('/[^\w.\- ()áéíóúñüÁÉÍÓÚÑÜ]+/u', '_', $f->getClientOriginalName()) ?: "documento.{$ext}", 0, 191);
        return [$path, $nombre, AttachmentService::mimeDe($path), (int) $f->getSize()];
    }
}
