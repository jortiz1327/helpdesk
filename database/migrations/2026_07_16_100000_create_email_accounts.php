<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Buzones de soporte del CANAL CORREO: IMAP entrante (los correos entran como
 * tickets) + SMTP saliente (las respuestas salen). Las contraseñas se guardan
 * ENCRIPTADAS (cast 'encrypted' en el modelo EmailAccount).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_accounts', function (Blueprint $t) {
            $t->id();
            $t->string('email');
            $t->string('from_name')->nullable();

            // Entrante (IMAP)
            $t->string('imap_host')->nullable();
            $t->unsignedSmallInteger('imap_port')->default(993);
            $t->string('imap_encryption', 10)->default('ssl'); // ssl | tls | none
            $t->string('imap_user')->nullable();
            $t->text('imap_password')->nullable();             // encriptada

            // Saliente (SMTP)
            $t->string('smtp_host')->nullable();
            $t->unsignedSmallInteger('smtp_port')->default(465);
            $t->string('smtp_encryption', 10)->default('ssl'); // ssl | tls | none
            $t->string('smtp_user')->nullable();
            $t->text('smtp_password')->nullable();             // encriptada

            $t->boolean('active')->default(true);
            $t->timestamp('last_check_at')->nullable();        // último sondeo IMAP
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_accounts');
    }
};
