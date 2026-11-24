<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Services\Ai\CoherenciaConocimiento as C;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CoherenciaConocimientoTest extends TestCase
{
    use RefreshDatabase;

    private function claves(string $texto, string $ambito): array
    {
        return array_column(C::contradicciones($texto, $ambito), 'hecho');
    }

    public function test_detecta_los_hechos_que_se_contradicen(): void
    {
        $this->assertSame(['correo-ajeno'], $this->claves('Escriba a juan@otraempresa.com con el material', 'fichero'));
        $this->assertSame([], $this->claves('Escriba a postventa@aemegroup.com con el material', 'fichero'));
        $this->assertContains('garantia-con-correo', $this->claves('Para la garantía escriba a postventa@aemegroup.com', 'fichero'));
        $this->assertSame([], $this->claves('Para garantías NUNCA des un correo (postventa@aemegroup.com es solo de material)', 'fichero'));
        $this->assertSame(['varta-compatible'], $this->claves('Las pilas VARTA son compatibles con las etiquetas', 'fichero'));
        $this->assertSame([], $this->claves('Compatibles: Beidongli, HenliMax y NSN; VARTA no es compatible', 'fichero'));
        $this->assertSame([], $this->claves('Algunas marcas, como Duracell o Varta, pueden dar problemas de grosor. Se recomiendan Beidongli, HenliMax o NSN', 'fichero'));
        $this->assertSame(['pila-otra-referencia'], $this->claves('La pila es una CR2032', 'plantilla'));
        $this->assertSame([], $this->claves('La pila es una CR2450 (AM-BZ2450)', 'plantilla'));
        $this->assertSame(['reinicio-minutos'], $this->claves('Quite las pilas unos 5 minutos y vuelva a ponerlas', 'plantilla'));
        $this->assertSame([], $this->claves('Quite las pilas 15 minutos y vuelva a ponerlas', 'plantilla'));
        // Una plantilla con un paso por línea: el tiempo no está en la misma línea que las pilas, y se escribe «30min».
        $this->assertSame(['reinicio-minutos'], $this->claves("Retire la tapa.\nRetire las pilas.\nEspere 30min.\nVuelva a introducir las pilas.", 'plantilla'));
        $this->assertSame([], $this->claves("Retire las pilas.\nEspere 15 minutos.\nVuelva a introducirlas.", 'plantilla'));
        $this->assertSame([], $this->claves("El menú cambia en unos 5 min.\nSi no cambia, fuerce la etiqueta.", 'plantilla'), 'sin pilas ni reinicio cerca no cuenta');
        $this->assertSame([], $this->claves('Espere 5 minutos por etiqueta antes de forzar', 'fichero'), '5 minutos sin pilas ni reinicio es otra cosa');
        $this->assertSame(['anadir-etiqueta-solo-plataforma'], $this->claves('Entre en Conectividad > Etiquetas > Añadir etiqueta', 'ticket'));
        $this->assertSame([], $this->claves('Conectividad > Etiquetas > Añadir etiqueta es solo del administrador de la plataforma', 'fichero'));
        $this->assertSame([], $this->claves('En el plano pulse Añadir etiqueta', 'fichero'), 'es otro botón');
        $this->assertContains('farmacia-garantia-expofarm', $this->claves('La garantía de una farmacia: escriba a postventa', 'plantilla'));
    }

    public function test_los_tickets_antiguos_solo_se_comprueban_con_los_hechos_de_su_ambito(): void
    {
        // Un ticket antiguo puede dar un correo distinto (no es un manual): no cuenta. Recomendar VARTA sí.
        $this->assertSame([], $this->claves('Escribe a otro@sitio.com', 'ticket'));
        $this->assertSame(['varta-compatible'], $this->claves('Las VARTA valen perfectamente', 'ticket'));
    }

    public function test_los_manuales_y_reglas_del_repositorio_no_se_contradicen(): void
    {
        $this->assertSame([], C::revisarFicheros(), 'hay contradicciones en resources/: ' . json_encode(C::revisarFicheros(), JSON_UNESCAPED_UNICODE));
    }

    public function test_marca_como_desfasados_los_tickets_que_contradicen_y_deja_el_resto(): void
    {
        $ins = fn (string $id, string $resp) => DB::table('ai_documents')->insert([
            'source_type' => 'ticket', 'source_id' => $id, 'title' => 't' . $id, 'content' => "Pregunta del cliente:\nla etiqueta no funciona\n\nRespuesta del equipo:\n" . $resp,
            'desfasado' => 0, 'indexed_at' => now(),
        ]);
        $ins('1', 'Las pilas VARTA son compatibles, pruebe con unas nuevas');
        $ins('2', 'Pruebe con pilas Beidongli y quite las pilas 15 minutos');
        $simulado = C::revisarBase(false);
        $this->assertSame(1, $simulado['tickets_marcados']);
        $this->assertSame(0, DB::table('ai_documents')->where('desfasado', 1)->count(), 'en simulación no se marca nada');
        C::revisarBase(true);
        $this->assertSame(['1'], DB::table('ai_documents')->where('desfasado', 1)->pluck('source_id')->all());
    }

    public function test_detecta_correcciones_y_plantillas_indexadas_que_contradicen(): void
    {
        DB::table('ai_documents')->insert(['source_type' => 'plantilla', 'source_id' => '9', 'title' => 'Reinicio', 'content' => 'Quítele las pilas unos 5 minutos', 'indexed_at' => now()]);
        $r = C::revisarBase(false);
        $this->assertSame('reinicio-minutos', $r['hallazgos'][0]['hecho']);
        $this->assertStringContainsString('Reinicio', $r['hallazgos'][0]['donde']);
    }

    public function test_el_resumen_avisa_cuando_hay_contradicciones_o_no_se_ha_revisado(): void
    {
        $this->assertSame('sin_revisar', C::resumen()['nivel']);
        Setting::put('ai_revision_conocimiento', json_encode(['fecha' => now()->toDateTimeString(), 'total' => 2, 'ficheros' => [], 'base' => [], 'tickets_marcados' => 0]));
        $this->assertSame('contradicciones', C::resumen()['nivel']);
        Setting::put('ai_revision_conocimiento', json_encode(['fecha' => now()->toDateTimeString(), 'total' => 0, 'ficheros' => [], 'base' => [], 'tickets_marcados' => 0]));
        $this->assertSame('ok', C::resumen()['nivel']);
        Setting::put('ai_revision_conocimiento', json_encode(['fecha' => now()->subDays(20)->toDateTimeString(), 'total' => 0, 'ficheros' => [], 'base' => [], 'tickets_marcados' => 0]));
        $this->assertSame('retrasada', C::resumen()['nivel']);
    }

    public function test_el_comando_guarda_el_resultado_y_el_endpoint_solo_lo_lanza_quien_configura(): void
    {
        $this->artisan('ai:revisar-conocimiento')->assertSuccessful();
        $this->assertNotNull(C::resumen()['ultima']);
        $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);
        $enc = \App\Models\User::create(['name' => 'Enc', 'email' => 'e@x.com', 'password' => bcrypt('x')]);
        $enc->assignRole('encargado_soporte');
        $ag = \App\Models\User::create(['name' => 'Ag', 'email' => 'a@x.com', 'password' => bcrypt('x')]);
        $ag->assignRole('agente');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        $url = '/api/ai_suggestions.php?action=admin_revisar';
        $this->withHeader('X-App-Token', \App\Services\TokenService::make($ag))->postJson($url)->assertStatus(403);
        $r = $this->withHeader('X-App-Token', \App\Services\TokenService::make($enc))->postJson($url)->assertOk();
        $this->assertSame(0, $r->json('total'));
        $this->assertArrayHasKey('revision', $r->json());
    }
}
