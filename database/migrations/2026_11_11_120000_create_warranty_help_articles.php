<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Centro de ayuda de Gestión de garantías. Los artículos de serie viven en el código
 * (App\Services\WarrantyHelp); aquí van los que se cambian (slug = el de serie) y los
 * que se añaden (slug null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_help_articles', function (Blueprint $t) {
            $t->id();
            $t->string('slug', 40)->nullable()->unique();
            $t->string('section', 30);
            $t->string('title', 120);
            $t->string('summary', 300)->nullable();
            $t->text('body');
            $t->json('related')->nullable();
            $t->boolean('active')->default(true);
            $t->unsignedInteger('sort')->default(0);
            $t->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_help_articles');
    }
};
