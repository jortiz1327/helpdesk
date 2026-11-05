<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Segunda cuenta de un servidor ZKONG: la que crea PRODUCTOS (la de administrador no
 * puede). La usan los productos de las garantías en el cloud. Contraseña y token cifrados
 * con la APP_KEY, igual que los de la cuenta de administrador, y con su propio comercio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('zkong_servers', function (Blueprint $t) {
            $t->string('product_username', 120)->nullable()->after('public_key_sha256');
            $t->text('product_password')->nullable()->after('product_username');
            $t->text('product_token')->nullable()->after('product_password');
            $t->unsignedBigInteger('product_merchant_id')->nullable()->after('product_token');
            $t->unsignedBigInteger('product_agency_id')->nullable()->after('product_merchant_id');
            $t->timestamp('product_token_updated_at')->nullable()->after('product_agency_id');
        });
    }

    public function down(): void
    {
        Schema::table('zkong_servers', function (Blueprint $t) {
            $t->dropColumn(['product_username', 'product_password', 'product_token', 'product_merchant_id', 'product_agency_id', 'product_token_updated_at']);
        });
    }
};
