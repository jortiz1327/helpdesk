<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cliente y proyecto del portal AEME de los que cuelga la garantía (los nombres se
// guardan también, por si el portal cambia o no responde) y la primera fecha de contacto.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('warranties', function (Blueprint $t) {
            $t->date('first_contact_at')->nullable()->after('code');
            $t->unsignedInteger('portal_client_id')->nullable()->after('received_at');
            $t->unsignedInteger('portal_project_id')->nullable()->after('client_name');
        });
    }

    public function down(): void
    {
        Schema::table('warranties', function (Blueprint $t) {
            $t->dropColumn(['first_contact_at', 'portal_client_id', 'portal_project_id']);
        });
    }
};
