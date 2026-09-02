<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Campañas por CORREO además de WhatsApp. WhatsApp obliga a plantillas aprobadas por Meta;
 * el correo no: es un asunto + cuerpo HTML que se envía por SMTP (mismo MailService del
 * helpdesk). Se añade el canal y los campos propios del correo, y se hacen nullable los
 * campos que solo aplican a WhatsApp (template_name, wa_id del destinatario).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->string('channel', 20)->default('whatsapp')->after('title');   // whatsapp | email
            $t->string('subject')->nullable()->after('template_name');        // asunto (correo)
            $t->longText('body_html')->nullable()->after('subject');          // cuerpo (correo)
        });
        // El correo no usa plantilla de Meta.
        Schema::table('campaigns', fn (Blueprint $t) => $t->string('template_name')->nullable()->change());

        Schema::table('campaign_recipients', function (Blueprint $t) {
            $t->string('email')->nullable()->after('wa_id');
        });
        // El destinatario de correo no tiene wa_id.
        Schema::table('campaign_recipients', fn (Blueprint $t) => $t->string('wa_id')->nullable()->change());
    }

    public function down(): void
    {
        Schema::table('campaigns', function (Blueprint $t) {
            $t->dropColumn(['channel', 'subject', 'body_html']);
        });
        Schema::table('campaign_recipients', function (Blueprint $t) {
            $t->dropColumn('email');
        });
    }
};
