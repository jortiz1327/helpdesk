<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * DATOS DE PRUEBA (para el servidor de demo). NO se ejecuta con `db:seed` normal;
 * se lanza a mano:  php artisan db:seed --class=Database\\Seeders\\TestDataSeeder --force
 *
 * Crea 3 agentes (Robert, Ian, Juan — contraseña «agente1234») y 10 tickets
 * (1 de WhatsApp, 2 de web, 7 de correo) con clientes y conversación, para tener
 * la bandeja con material realista. Ejecútalo UNA vez.
 */
class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        // ---------- 3 agentes de prueba ----------
        $agentes = [];
        foreach ([['Robert', 'robert@aemegroup.com'], ['Ian', 'ian@aemegroup.com'], ['Juan', 'juan@aemegroup.com']] as [$name, $email]) {
            $u = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make('agente1234')]);
            $u->syncRoles([config('rbac.default_role')]);   // 'agente'
            $agentes[] = $u->id;
        }

        $cats = DB::table('ticket_categories')->pluck('id', 'key');   // soporte / garantias / pedidos_facturas

        // Siguiente número de código dentro del mes (TK-AAMM-NNNN), sin colisiones.
        $prefix = 'TK-' . date('ym') . '-';
        $last = DB::table('tickets')->where('code', 'like', $prefix . '%')->orderByDesc('code')->value('code');
        $n = $last ? (int) substr($last, -4) : 0;

        // [canal, nombre, email, wa_id, asunto, cuerpo, categoría, estado, prioridad, agente(0-2|null), díasAtrás, respuesta|null, resuelto]
        $tickets = [
            ['whatsapp', 'Marta · Hotel Brisa', null, '34611223344', 'Las etiquetas del pasillo 3 no cambian de precio', 'Buenas, en el pasillo 3 los precios no se actualizan desde ayer.', 'soporte', 'en_progreso', 'alta', 0, 1, 'Hola Marta, lo estamos mirando. ¿Los repetidores de esa zona están encendidos?', false],
            ['web', 'Carlos Ruiz', 'carlos@super-ahorro.es', null, 'No puedo clonar el servicio de mediodía', 'Al clonar el servicio de mediodía no me deja guardar, me da un error.', 'soporte', 'nuevo', 'media', null, 0, null, false],
            ['web', 'Lucía Gómez', 'lucia@panaderia-lucia.com', null, 'Etiqueta rota en la caja 2', 'Se ha roto una etiqueta de la caja 2, ¿cómo pido una reposición?', 'garantias', 'en_progreso', 'media', 1, 2, 'Hola Lucía, anota el código de la etiqueta y te gestionamos la reposición.', false],
            ['email', 'Pedro Sanz', 'pedro@restaurante-sanz.es', null, 'Repetidor AP apagado en la tienda del centro', 'El repetidor de la tienda del centro está en rojo y no responde.', 'soporte', 'esperando_respuesta', 'alta', 2, 3, 'Prueba a desconectarlo 10 segundos y vuelve a enchufarlo. ¿Sigue en rojo?', false],
            ['email', 'Ana Torres', 'ana@fruteria-torres.com', null, 'Factura duplicada del pedido 8842', 'Me han cobrado dos veces el pedido 8842, ¿lo podéis revisar?', 'pedidos_facturas', 'nuevo', 'media', null, 1, null, false],
            ['email', 'Jorge Melo', 'jorge@bar-melo.es', null, 'El menú de verano no se aplica', 'Cambié al menú de verano pero las etiquetas siguen mostrando el de invierno.', 'soporte', 'resuelto', 'media', 0, 6, 'Estaba el servicio sin forzar. Ya se muestra correctamente. ¡Un saludo!', true],
            ['email', 'Nuria Vidal', 'nuria@hotelvidal.com', null, 'Template not matched en 5 etiquetas', 'Cinco etiquetas muestran el mensaje "Template not matched".', 'soporte', 'en_progreso', 'media', 1, 2, 'Hay que reasignar la plantilla correcta a esas etiquetas. Lo hacemos y te confirmamos.', false],
            ['email', 'Diego Pardo', 'diego@carniceria-pardo.es', null, 'Solicito reposición de material', 'Necesito reponer 20 etiquetas nuevas para la tienda.', 'garantias', 'nuevo', 'baja', null, 1, null, false],
            ['email', 'Sonia Marc', 'sonia@cafe-marc.com', null, 'Cambiar la hora del cambio de servicio', '¿Cómo cambio la hora a la que cambia el servicio?', 'soporte', 'resuelto', 'baja', 2, 8, 'Se cambia en Servicios → horario. Te dejo el paso a paso. ¡Resuelto!', true],
            ['email', 'Raúl Ibáñez', 'raul@bodega-ibanez.es', null, 'Las etiquetas no cargan tras la actualización', 'Después de la última actualización, las etiquetas no cargan en ninguna tienda.', 'soporte', 'nuevo', 'urgente', null, 0, null, false],
        ];

        foreach ($tickets as $i => $t) {
            [$canal, $nombre, $email, $waId, $asunto, $cuerpo, $catKey, $estado, $prio, $agIdx, $dias, $respuesta, $resuelto] = $t;

            // Contacto (por wa_id en WhatsApp, por correo en el resto).
            $clave = $canal === 'whatsapp' ? ['wa_id' => $waId] : ['email' => $email];
            $contactId = DB::table('contacts')->where($clave)->value('id');
            if (!$contactId) {
                $contactId = DB::table('contacts')->insertGetId(array_merge($clave, [
                    'name' => $nombre, 'created_at' => now(),
                ]));
            }

            $creado    = Carbon::now()->subDays($dias)->subHours(3 + $i);
            $respondido = $respuesta ? (clone $creado)->addHours(2) : null;
            $ultimo    = $respondido ?: $creado;
            $n++;
            $code = $prefix . str_pad((string) $n, 4, '0', STR_PAD_LEFT);

            $ticketId = DB::table('tickets')->insertGetId([
                'code'              => $code,
                'subject'           => $asunto,
                'category_id'       => $cats[$catKey] ?? null,
                'status'            => $estado,
                'priority'          => $prio,
                'channel'           => $canal,
                'contact_id'        => $contactId,
                'assigned_to'       => $agIdx !== null ? $agentes[$agIdx] : null,
                'opened_at'         => $creado,
                'first_response_at' => $respondido,
                'resolved_at'       => $resuelto ? $ultimo : null,
                'last_message_at'   => $ultimo,
                'last_direction'    => $respuesta ? 'out' : 'in',
                'created_at'        => $creado,
                'updated_at'        => $ultimo,
            ]);

            // Mensaje del cliente (entrante).
            DB::table('messages')->insert([
                'contact_id' => $contactId, 'ticket_id' => $ticketId, 'direction' => 'in',
                'channel' => $canal, 'type' => 'text', 'body' => nl2br(e($cuerpo)), 'is_html' => 1,
                'status' => 'received', 'created_at' => $creado,
            ]);

            // Respuesta del técnico (saliente), si la hay.
            if ($respuesta) {
                DB::table('messages')->insert([
                    'contact_id' => $contactId, 'ticket_id' => $ticketId, 'direction' => 'out',
                    'channel' => $canal, 'type' => 'text', 'body' => '<p>' . e($respuesta) . '</p>', 'is_html' => 1,
                    'author_user_id' => $agIdx !== null ? $agentes[$agIdx] : null,
                    'status' => 'sent', 'created_at' => $respondido,
                ]);
            }

            DB::table('contacts')->where('id', $contactId)->update([
                'last_message' => mb_substr(strip_tags($respuesta ?: $cuerpo), 0, 160),
                'last_time'    => $ultimo,
            ]);
        }

        $this->command->info('Datos de prueba: 3 agentes (agente1234) + ' . count($tickets) . ' tickets creados.');
    }
}
