<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Categorías de ticket. Son EDITABLES desde Administración: esto es solo la
 * siembra inicial con las tres del brief. Si el cliente prefiere otras
 * (técnico / facturación / ventas), se cambian sin tocar código.
 */
class TicketCategoriesSeeder extends Seeder
{
    public function run(): void
    {
        // Categorías DEFINITIVAS (cliente, 15/07/2026): Soporte · Garantías · Pedidos y facturas.
        $cats = [
            ['key' => 'soporte',          'name' => 'Soporte',            'description' => 'Incidencias técnicas y ayuda general', 'color' => '#2563eb', 'sla_hours' => 24, 'position' => 1],
            ['key' => 'garantias',        'name' => 'Garantías',          'description' => 'Reclamaciones y garantías de producto', 'color' => '#f59e0b', 'sla_hours' => 48, 'position' => 2],
            ['key' => 'pedidos_facturas', 'name' => 'Pedidos y facturas', 'description' => 'Pedidos, pagos, facturas y suscripciones', 'color' => '#10b981', 'sla_hours' => 48, 'position' => 3],
        ];

        foreach ($cats as $c) {
            DB::table('ticket_categories')->updateOrInsert(['key' => $c['key']], $c);
        }
    }
}
