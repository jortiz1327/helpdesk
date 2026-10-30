<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Configuración de Gestión de garantías: estados (los de siempre más los de base44,
 * editables), categorías, reglas de alerta (días de aviso y de crítico) y plantillas de
 * diagnóstico. status_changed_at dice desde cuándo está la garantía en su estado, para
 * las alertas por estado (pendiente de cliente, de envío…).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warranty_statuses', function (Blueprint $t) {
            $t->string('key', 30)->primary();
            $t->string('label', 60);
            $t->string('color', 9);                        // #rrggbb
            $t->boolean('closed')->default(false);         // cuenta como cerrada (fecha de cierre, fuera de «abiertas»)
            $t->boolean('active')->default(true);          // inactivo: no se ofrece, pero se sigue viendo
            $t->boolean('system')->default(false);         // de serie: no se borra (se puede renombrar)
            $t->unsignedSmallInteger('sort')->default(0);
        });
        $estados = [
            ['no_iniciado', 'No iniciado', '#64748b', false], ['en_curso', 'En curso', '#2563eb', false],
            ['pte_presupuesto', 'Pte. presupuesto', '#9333ea', false], ['falta_stock', 'Falta stock', '#ea580c', false],
            ['pte_cliente', 'Pte. cliente', '#c27803', false], ['pte_envio', 'Pte. envío', '#0891b2', false],
            ['enviada', 'Enviada', '#4f46e5', false], ['pte_zkong', 'Pte. ZKONG', '#7c3aed', false],
            ['rechazado_cliente', 'Rechazado por cliente', '#e11d48', false], ['resuelta', 'Resuelta', '#12925a', true],
            ['cerrada', 'Cerrada', '#16a34a', true], ['descartada', 'Descartada', '#94a3b8', true],
        ];
        foreach ($estados as $n => [$k, $l, $c, $closed]) {
            DB::table('warranty_statuses')->insert(['key' => $k, 'label' => $l, 'color' => $c, 'closed' => $closed, 'active' => true, 'system' => true, 'sort' => $n]);
        }

        Schema::create('warranty_categories', function (Blueprint $t) {
            $t->id();
            $t->string('name', 60)->unique();
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
        });
        $usadas = DB::table('warranties')->whereNotNull('category')->where('category', '!=', '')->distinct()->pluck('category')->all();
        $cats = array_values(array_unique(array_merge(['Hoteles', 'Retail', 'Farmacias', 'Supermercados', 'Universidad', 'Logística', 'Vinoteca', 'Milar'], $usadas, ['Otros'])));
        foreach ($cats as $n => $c) {
            DB::table('warranty_categories')->insert(['name' => mb_substr($c, 0, 60), 'active' => true, 'sort' => $n]);
        }

        Schema::create('warranty_alert_rules', function (Blueprint $t) {
            $t->string('key', 20)->primary();
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('warn_days')->default(0);
            $t->unsignedSmallInteger('crit_days')->default(0);
        });
        foreach ([['cliente', 7, 14], ['muy_urgente', 0, 0], ['zkong', 10, 20], ['envio', 3, 7], ['sin_responsable', 0, 0],
            ['stock', 7, 15], ['inactividad', 5, 10], ['rma', 7, 15]] as [$k, $w, $c]) {
            DB::table('warranty_alert_rules')->insert(['key' => $k, 'active' => true, 'warn_days' => $w, 'crit_days' => $c]);
        }

        Schema::create('warranty_diagnosis_templates', function (Blueprint $t) {
            $t->id();
            $t->string('title', 120);
            $t->text('body');
            $t->boolean('active')->default(true);
            $t->unsignedSmallInteger('sort')->default(0);
            $t->timestamps();
        });
        $base = 'Durante la revisión visual del dispositivo se ha detectado ';
        foreach ([
            ['Separación/deformación del armazón', $base . 'una separación visible del armazón, con deformación de la carcasa que compromete la integridad estructural del equipo. No se puede garantizar la estanqueidad ni la protección de los componentes internos.'],
            ['Pines/contactos de batería dañados', $base . 'que los pines y contactos de la batería presentan daños físicos o deformación. Esto impide la correcta conexión y alimentación del equipo. Se requiere reparación o sustitución del conector de batería.'],
            ['Filtración de líquidos', $base . 'evidencia de filtración de líquidos en el interior del equipo. Se observan signos de corrosión en los componentes electrónicos y manchas de humedad. La unidad no es operativa y requiere sustitución.'],
            ['Rotura interna de display', $base . 'una rotura interna del display. La pantalla presenta daños no visibles desde el exterior que afectan a la correcta visualización de la información. Se requiere sustitución del módulo de pantalla.'],
            ['Otro', $base . 'una incidencia que requiere evaluación adicional.'],
        ] as $n => [$title, $body]) {
            DB::table('warranty_diagnosis_templates')->insert(['title' => $title, 'body' => $body, 'active' => true, 'sort' => $n, 'created_at' => now(), 'updated_at' => now()]);
        }

        Schema::table('warranties', function (Blueprint $t) {
            $t->dateTime('status_changed_at')->nullable()->after('status');
        });
        // Desde el último cambio de estado del historial; si no lo hay, desde el alta.
        $ultimos = DB::table('warranty_events')->where('field', 'status')->groupBy('warranty_id')
            ->selectRaw('warranty_id, max(created_at) as ultimo')->pluck('ultimo', 'warranty_id');
        foreach (DB::table('warranties')->get(['id', 'created_at']) as $w) {
            DB::table('warranties')->where('id', $w->id)->update(['status_changed_at' => $ultimos[$w->id] ?? $w->created_at]);
        }
    }

    public function down(): void
    {
        Schema::table('warranties', fn (Blueprint $t) => $t->dropColumn('status_changed_at'));
        Schema::dropIfExists('warranty_diagnosis_templates');
        Schema::dropIfExists('warranty_alert_rules');
        Schema::dropIfExists('warranty_categories');
        Schema::dropIfExists('warranty_statuses');
    }
};
