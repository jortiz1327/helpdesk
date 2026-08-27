<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Carga un dump .sql de Faveo en la MISMA BD del helpdesk, prefijando las tablas
 * (p. ej. `fav_`) para que no choquen con las tuyas. Todo por PHP (artisan), sin
 * necesidad de SSH ni de crear una base de datos aparte.
 *
 *   php artisan faveo:load /ruta/faveo.sql            (carga como fav_*)
 *   php artisan faveo:load /ruta/faveo.sql --prefix=fav_
 *   php artisan faveo:load --drop                      (borra las tablas fav_*)
 *
 * Después: php artisan faveo:import --apply --prefix=fav_
 */
class ImportFaveoLoad extends Command
{
    protected $signature = 'faveo:load
        {file? : Ruta al fichero faveo.sql (súbelo antes con el Administrador de archivos)}
        {--prefix=fav_ : Prefijo con el que se cargan las tablas de Faveo}
        {--drop : No carga nada: BORRA las tablas que empiecen por ese prefijo}';

    protected $description = 'Carga (o borra) el dump de Faveo en la misma BD, con prefijo — sin SSH ni BD aparte';

    public function handle(): int
    {
        $prefix = (string) $this->option('prefix');
        if ($prefix === '' || !preg_match('/^[a-zA-Z0-9_]+$/', $prefix)) {
            $this->error('Prefijo no válido (solo letras/números/guion bajo).');
            return self::FAILURE;
        }

        if ($this->option('drop')) {
            return $this->drop($prefix);
        }

        $file = (string) $this->argument('file');
        if ($file === '' || !is_readable($file)) {
            $this->error("No encuentro el fichero: «$file». Súbelo con el Administrador de archivos de Plesk y pasa su ruta.");
            return self::FAILURE;
        }

        // Empezar de cero: si ya había tablas con ese prefijo (carga anterior), fuera.
        $this->drop($prefix, quiet: true);

        $this->info('Cargando ' . round(filesize($file) / 1048576) . ' MB desde ' . basename($file) . ' como tablas «' . $prefix . '*»…');

        $pdo = DB::connection()->getPdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        $pdo->exec("SET sql_mode=''");

        $re = '/(CREATE TABLE|INSERT INTO|ALTER TABLE|DROP TABLE IF EXISTS|REFERENCES|LOCK TABLES) `([a-zA-Z0-9_]+)`/';
        $rep = '$1 `' . $prefix . '$2`';

        $fh = fopen($file, 'rb');
        $buf = '';
        $inStr = false;
        $stmts = 0; $errores = 0;

        $ejecutar = function (string $sql) use ($pdo, $re, $rep, &$stmts, &$errores) {
            $sql = trim($sql);
            if ($sql === '') return;
            $sql = preg_replace($re, $rep, $sql);   // prefija los nombres de tabla
            try { $pdo->exec($sql); $stmts++; }
            catch (\Throwable $e) { $errores++; if ($errores <= 5) $this->warn('  · sentencia omitida: ' . mb_substr($e->getMessage(), 0, 120)); }
        };

        while (($line = fgets($fh)) !== false) {
            // Fuera comentarios y líneas en blanco, PERO solo cuando no estamos dentro de
            // un texto (un cuerpo de correo puede empezar una línea con «--»).
            if (!$inStr) {
                $lt = ltrim($line);
                if ($lt === '' || str_starts_with($lt, '-- ') || $lt === "--\n" || str_starts_with($lt, '/*!40')) {
                    // los /*!… */; son SET ejecutables → los dejamos pasar salvo el bloque de cabecera inofensivo
                    if (str_starts_with($lt, '-- ') || $lt === '' || $lt === "--\n") continue;
                }
            }
            $buf .= $line;
            // Extraer sentencias completas (hasta un «;» fuera de comillas).
            $i = 0; $n = strlen($buf); $start = 0;
            while ($i < $n) {
                if ($inStr) {
                    $i += strcspn($buf, "'\\", $i);
                    if ($i >= $n) break;
                    if ($buf[$i] === '\\') { $i += 2; continue; }
                    $inStr = false; $i++;
                } else {
                    $i += strcspn($buf, "';", $i);
                    if ($i >= $n) break;
                    if ($buf[$i] === "'") { $inStr = true; $i++; }
                    else { $ejecutar(substr($buf, $start, $i - $start + 1)); $i++; $start = $i; }
                }
            }
            $buf = substr($buf, $start);
        }
        if (trim($buf) !== '') $ejecutar($buf);   // por si el fichero no acaba en «;»
        fclose($fh);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');

        $this->info("Cargado: $stmts sentencias · $errores omitidas.");
        $this->line('Ahora: <info>php artisan faveo:import --apply --prefix=' . $prefix . '</info>');
        return self::SUCCESS;
    }

    /** Borra todas las tablas de la BD actual que empiecen por el prefijo. */
    private function drop(string $prefix, bool $quiet = false): int
    {
        $db = DB::connection()->getDatabaseName();
        $like = str_replace('_', '\\_', $prefix) . '%';
        $tablas = DB::table('information_schema.tables')
            ->where('table_schema', $db)->where('table_name', 'like', $like)
            ->pluck('table_name');
        if ($tablas->isEmpty()) { if (!$quiet) $this->info('No hay tablas con prefijo «' . $prefix . '».'); return self::SUCCESS; }

        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach ($tablas as $t) DB::statement('DROP TABLE IF EXISTS `' . $t . '`');
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        if (!$quiet) $this->info('Borradas ' . $tablas->count() . ' tablas «' . $prefix . '*».');
        return self::SUCCESS;
    }
}
