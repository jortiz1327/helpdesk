<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de TEXTO COMPLETO para el buscador de la bandeja (modo «ficha»).
 *
 * Hasta ahora ese buscador —el de diario— hacía `LIKE '%texto%'` sobre el asunto y
 * los datos del contacto. El comodín inicial anula cualquier índice: con 50.000
 * tickets es un escaneo completo (más el join a contacts) en CADA pulsación, y como
 * la búsqueda lanza dos consultas (conteo + página), casi el doble.
 *
 * Con FULLTEXT sobre `tickets.subject` y `contacts(name,email)`, el asunto y el
 * nombre/correo se resuelven por índice (`MATCH ... AGAINST`), igual que ya hace la
 * búsqueda «dentro de los mensajes». El código y el teléfono se quedan por subcadena
 * a propósito (no son «palabras»), pero sobre columnas/tablas pequeñas.
 *
 * Trabaja por PALABRAS con un mínimo de 3 letras (`innodb_ft_min_token_size`); para
 * términos más cortos el buscador cae a LIKE, que son casos raros.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!$this->existe('tickets', 'tickets_subject_fulltext')) {
            DB::statement('CREATE FULLTEXT INDEX tickets_subject_fulltext ON tickets (subject)');
        }
        if (!$this->existe('contacts', 'contacts_name_email_fulltext')) {
            DB::statement('CREATE FULLTEXT INDEX contacts_name_email_fulltext ON contacts (name, email)');
        }
    }

    public function down(): void
    {
        if ($this->existe('tickets', 'tickets_subject_fulltext')) {
            DB::statement('DROP INDEX tickets_subject_fulltext ON tickets');
        }
        if ($this->existe('contacts', 'contacts_name_email_fulltext')) {
            DB::statement('DROP INDEX contacts_name_email_fulltext ON contacts');
        }
    }

    protected function existe(string $tabla, string $indice): bool
    {
        foreach (DB::select("SHOW INDEX FROM {$tabla}") as $i) {
            if ($i->Key_name === $indice) return true;
        }
        return false;
    }
};
