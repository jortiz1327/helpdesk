<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * La tienda del Cloud donde la cuenta de productos crea los productos de las garantías (la
 * del almacén). Se configura en Servidores ZKONG junto a la cuenta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zkong_servers', function (Blueprint $t) {
            $t->string('product_store_id', 40)->nullable()->after('product_username');
        });
    }

    public function down(): void
    {
        Schema::table('zkong_servers', function (Blueprint $t) {
            $t->dropColumn('product_store_id');
        });
    }
};
