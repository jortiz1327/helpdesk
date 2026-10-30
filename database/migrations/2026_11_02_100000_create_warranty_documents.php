<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Documentos de las garantías: quién paga cada dispositivo (garantía, cliente o pilas),
 * la tarifa por producto y por tipo de pila, los presupuestos y albaranes (generados
 * aquí con sus líneas o subidos de fuera), el informe de resolución y los ajustes de
 * los documentos (datos de la empresa, condiciones y plantilla del correo de resolución).
 */
return new class extends Migration
{
    // Quién paga, según cómo quedó cada dispositivo (el técnico lo puede cambiar).
    private const COBERTURA = [
        'correcta' => 'garantia', 'recuperada' => 'garantia', 'reparada' => 'garantia',
        'no_reproduce' => 'garantia', 'defectuosa' => 'garantia', 'danada' => 'cliente', 'bateria' => 'pilas',
    ];

    public function up(): void
    {
        Schema::table('warranty_items', function (Blueprint $t) {
            $t->string('coverage', 10)->nullable()->after('resolution');   // garantia | cliente | pilas
        });
        foreach (self::COBERTURA as $res => $cov) {
            DB::table('warranty_items')->where('resolution', $res)->update(['coverage' => $cov]);
        }

        // Tarifa: product = referencia AEME (etiqueta o antena), battery = tipo de pila,
        // service = otros conceptos que se añaden a mano (portes, mano de obra…).
        Schema::create('warranty_prices', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 10);
            $t->string('ref', 80);
            $t->string('label', 160)->nullable();
            $t->decimal('price', 10, 2)->default(0);
            $t->timestamps();
            $t->unique(['kind', 'ref']);
        });
        $ahora = now();
        DB::table('warranty_prices')->insert([
            ['kind' => 'service', 'ref' => 'portes', 'label' => 'Gastos de envío', 'price' => 0, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['kind' => 'service', 'ref' => 'revision', 'label' => 'Revisión y diagnóstico', 'price' => 0, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);

        Schema::create('warranty_documents', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warranty_id')->constrained('warranties')->cascadeOnDelete();
            $t->string('kind', 12);                        // presupuesto | albaran | informe | factura | otro
            $t->string('coverage', 10)->nullable();        // garantia | cliente | pilas
            $t->string('source', 10)->default('generado'); // generado | subido
            $t->string('number', 40)->nullable();
            $t->string('status', 12)->nullable();          // borrador | enviado | aceptado | rechazado | entregado | anulado
            $t->decimal('vat', 5, 2)->default(21);
            $t->decimal('total', 12, 2)->nullable();       // de las líneas (generado) o escrito (subido)
            $t->text('note')->nullable();
            $t->string('path', 255)->nullable();           // fichero subido o PDF del informe
            $t->string('file_name', 190)->nullable();
            $t->unsignedInteger('size')->nullable();
            $t->foreignId('from_document_id')->nullable()->constrained('warranty_documents')->nullOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->index(['warranty_id', 'kind']);
            $t->index('number');
        });

        Schema::create('warranty_document_lines', function (Blueprint $t) {
            $t->id();
            $t->foreignId('warranty_document_id')->constrained('warranty_documents')->cascadeOnDelete();
            $t->unsignedSmallInteger('sort')->default(0);
            $t->string('ref', 80)->nullable();
            $t->string('description', 300);
            $t->json('codes')->nullable();                 // etiquetas o antenas de la línea
            $t->decimal('qty', 10, 2)->default(1);
            $t->decimal('price', 10, 2)->default(0);
        });

        // Ajustes de los documentos: clave → JSON.
        Schema::create('warranty_settings', function (Blueprint $t) {
            $t->string('key', 40)->primary();
            $t->json('value')->nullable();
            $t->timestamps();
        });
        DB::table('warranty_settings')->insert([
            ['key' => 'company', 'value' => json_encode(['name' => 'AEME', 'tax_id' => '', 'address' => '', 'email' => '', 'phone' => '']), 'created_at' => $ahora, 'updated_at' => $ahora],
            ['key' => 'documents', 'value' => json_encode([
                'vat' => 21,
                'validity_days' => 30,
                'conditions' => 'Presupuesto válido durante 30 días. Los dispositivos cubiertos por la garantía no tienen cargo.',
            ]), 'created_at' => $ahora, 'updated_at' => $ahora],
            ['key' => 'resolution_email', 'value' => json_encode([
                'subject' => 'Resolución de la garantía {codigo}',
                'body' => "Hola {cliente}:\n\nHemos terminado de revisar los dispositivos de la garantía {codigo} ({proyecto}).\n\n{resumen}\n\nTe adjuntamos el informe de resolución con el detalle de cada dispositivo{documentos}.\n\nUn saludo,\n{agente}",
            ]), 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('warranty_settings');
        Schema::dropIfExists('warranty_document_lines');
        Schema::dropIfExists('warranty_documents');
        Schema::dropIfExists('warranty_prices');
        Schema::table('warranty_items', fn (Blueprint $t) => $t->dropColumn('coverage'));
    }
};
