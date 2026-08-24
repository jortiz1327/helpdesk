<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * BANCO DE PRUEBAS a escala. Crea una base DE USAR Y TIRAR (nunca la real), la llena
 * con N tickets sintéticos, corre el diagnóstico `db:explain` sobre ese volumen y la
 * borra. Sirve para ver, en local, si a 50k hace falta algún índice — sin ensuciar
 * ni un solo dato real.
 *
 * RED DE SEGURIDAD: se niega a trabajar sobre cualquier base cuyo nombre no acabe en
 * «_bench» o «_test», y nunca sobre la base real.
 *
 *   php artisan db:bench                 (50.000 filas, base helpdesk_bench, y la borra)
 *   php artisan db:bench --rows=100000 --keep
 */
class DbBench extends Command
{
    protected $signature = 'db:bench {--rows=50000} {--db=helpdesk_bench} {--keep : No borrar la base al terminar} {--candidates : Añade los índices candidatos y repite el EXPLAIN (antes/después)}';

    protected $description = 'Carga N tickets sintéticos en una base de usar y tirar y corre db:explain';

    public function handle(): int
    {
        $target = (string) $this->option('db');
        $rows   = max(1000, (int) $this->option('rows'));
        $conn   = config('database.default');
        $real   = (string) config("database.connections.$conn.database");

        if (!str_ends_with($target, '_bench') && !str_ends_with($target, '_test')) {
            $this->error("ABORTADO: «{$target}» no acaba en «_bench»/«_test». Por seguridad solo trabajo sobre bases de prueba.");
            return self::FAILURE;
        }
        if ($target === $real) {
            $this->error("ABORTADO: «{$target}» es la base REAL. No pienso tocarla.");
            return self::FAILURE;
        }

        $this->info("Base de pruebas: «{$target}» (la real, «{$real}», no se toca).");
        DB::statement("CREATE DATABASE IF NOT EXISTS `{$target}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        // A partir de aquí, la conexión por defecto apunta a la base de pruebas.
        config(["database.connections.$conn.database" => $target]);
        DB::purge($conn); DB::reconnect($conn);

        $this->line('Montando el esquema (migrate:fresh)…');
        $this->call('migrate:fresh', ['--force' => true]);

        $this->line("Generando {$rows} tickets sintéticos…");
        $this->sembrar($rows);

        $this->newLine();
        $this->line(str_repeat('─', 60));
        $this->comment($this->option('candidates') ? 'ANTES (índices actuales):' : 'Plan de ejecución:');
        $this->call('db:explain');
        $this->line(str_repeat('─', 60));

        if ($this->option('candidates')) {
            $this->line('Añadiendo índices candidatos: (assigned_to, status) y (category_id, status, last_message_at)…');
            DB::statement('CREATE INDEX bench_assigned_status ON tickets (assigned_to, status)');
            DB::statement('CREATE INDEX bench_cat_status_lma ON tickets (category_id, status, last_message_at)');
            $this->newLine();
            $this->comment('DESPUÉS (con los índices candidatos):');
            $this->call('db:explain');
            $this->line(str_repeat('─', 60));
        }

        // Volver a la base real ANTES de borrar la de pruebas.
        config(["database.connections.$conn.database" => $real]);
        DB::purge($conn); DB::reconnect($conn);

        if ($this->option('keep')) {
            $this->warn("Base «{$target}» conservada (--keep). Bórrala con:  DROP DATABASE `{$target}`;");
        } else {
            DB::statement("DROP DATABASE IF EXISTS `{$target}`");
            $this->info("Base de pruebas «{$target}» borrada. Nada tocado en la real.");
        }
        return self::SUCCESS;
    }

    /** Llena la base de pruebas con datos verosímiles: agentes, categorías, contactos y N tickets. */
    private function sembrar(int $rows): void
    {
        // Estados válidos, leídos del propio ENUM (así el bench no depende de valores fijos).
        $estados = $this->enumValores('status') ?: ['nuevo', 'abierto', 'en_progreso', 'esperando_respuesta', 'resuelto', 'cerrado'];
        $vivos   = array_values(array_intersect($estados, ['nuevo', 'abierto', 'en_progreso', 'esperando_respuesta']));
        if (!$vivos) $vivos = [$estados[0]];

        // 5 agentes, 6 categorías; al primer agente se le asignan 2 categorías (su «área»),
        // que es justo lo que db:explain busca para simular a un agente realista.
        $agentes = [];
        foreach (range(1, 5) as $i) {
            $agentes[] = DB::table('users')->insertGetId([
                'name' => "Agente $i", 'email' => "agente$i@bench.local",
                'password' => bcrypt('x'), 'created_at' => now(),
            ]);
        }
        $cats = [];
        foreach (['Etiquetas', 'Menús', 'Repetidores', 'Facturación', 'Instalación', 'Otros'] as $j => $nombre) {
            $cats[] = DB::table('ticket_categories')->insertGetId([
                'key' => 'bench-' . $j, 'name' => $nombre, 'color' => '#888', 'position' => $j, 'active' => 1,
            ]);
        }
        DB::table('user_ticket_categories')->insert([
            ['user_id' => $agentes[0], 'category_id' => $cats[0]],
            ['user_id' => $agentes[0], 'category_id' => $cats[1]],
        ]);

        // 1.000 contactos para repartir (evita una ficha por ticket).
        $contactos = [];
        foreach (array_chunk(range(1, 1000), 500) as $trozo) {
            $lote = array_map(fn ($n) => ['name' => "Cliente $n", 'email' => "cli$n@bench.local"], $trozo);
            DB::table('contacts')->insert($lote);
        }
        $contactos = DB::table('contacts')->pluck('id')->all();

        $canales = ['email', 'whatsapp', 'web'];
        $prios   = ['baja', 'media', 'alta'];
        $ahora   = now();
        $bar = $this->output->createProgressBar((int) ceil($rows / 2000));

        for ($base = 0; $base < $rows; $base += 2000) {
            $lote = [];
            $n = min(2000, $rows - $base);
            for ($k = 0; $k < $n; $k++) {
                $i = $base + $k;
                $estado = $estados[array_rand($estados)];
                $resuelto = in_array($estado, ['resuelto', 'cerrado'], true);
                $creado = (clone $ahora)->subMinutes(random_int(0, 120 * 24 * 60));   // últimos ~120 días
                $ultAct = (clone $creado)->addMinutes(random_int(0, 5000));
                $lote[] = [
                    'code'            => 'BCH-' . str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                    'subject'         => 'Incidencia sintética ' . $i,
                    'status'          => $estado,
                    'priority'        => $prios[array_rand($prios)],
                    'channel'         => $canales[array_rand($canales)],
                    'contact_id'      => $contactos[array_rand($contactos)],
                    'assigned_to'     => random_int(0, 3) === 0 ? null : $agentes[array_rand($agentes)],   // ~25% sin asignar
                    'category_id'     => random_int(0, 4) === 0 ? null : $cats[array_rand($cats)],        // ~20% sin categoría
                    'last_direction'  => random_int(0, 1) ? 'in' : 'out',
                    'opened_at'       => $creado,
                    'created_at'      => $creado,
                    'updated_at'      => $ultAct,
                    'last_message_at' => $ultAct,
                    'resolved_at'     => $resuelto ? $ultAct : null,
                ];
            }
            DB::table('tickets')->insert($lote);
            $bar->advance();
        }
        $bar->finish();
        $this->newLine();
    }

    /** Valores de un ENUM de la tabla tickets (['nuevo','abierto',…]) o [] si no aplica. */
    private function enumValores(string $columna): array
    {
        // El nombre de columna es interno (no viene del usuario): va inline, SHOW no admite placeholders.
        $col = collect(DB::select("SHOW COLUMNS FROM tickets LIKE '" . str_replace("'", '', $columna) . "'"))->first();
        if (!$col || !preg_match("/^enum\\((.*)\\)$/i", $col->Type, $m)) return [];
        return array_map(fn ($v) => trim($v, "'"), str_getcsv($m[1]));
    }
}
