<?php

namespace App\Console\Commands;

use App\Services\MailService;
use Illuminate\Console\Command;

/**
 * Canal correo · entrada. Sondea los buzones IMAP y crea/actualiza tickets con
 * los correos nuevos. Se ejecuta cada minuto desde el scheduler (bootstrap/app.php).
 */
class MailFetch extends Command
{
    protected $signature = 'email:fetch';

    protected $description = 'Sondea los buzones IMAP y convierte los correos nuevos en tickets';

    public function handle(MailService $mail): int
    {
        $r = $mail->fetchAll();

        $this->info("Correos: {$r['mensajes']} · tickets nuevos: {$r['tickets_nuevos']} · adjuntos: {$r['adjuntos']}");
        foreach ($r['errores'] as $e) {
            $this->warn($e);
        }

        return self::SUCCESS;
    }
}
