<?php

namespace Tests\Feature;

use App\Models\Warranty;
use App\Models\WarrantyItem;
use App\Services\WarrantySteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Pasos que se marcan solos: comprobar en el Cloud (g1) y revisar todas (g2). */
class WarrantyStepsAutoTest extends TestCase
{
    use RefreshDatabase;

    private function garantia(): Warranty
    {
        $w = Warranty::create(['code' => 'G-2026-0009', 'status' => 'no_iniciado', 'client_name' => 'Grupo Sol',
            'last_activity_at' => now(), 'status_changed_at' => now()]);
        foreach (['A1', 'A2'] as $c) {
            WarrantyItem::create(['warranty_id' => $w->id, 'code' => $c, 'kind' => 'esl', 'lookup_state' => 'found', 'resolution' => 'pendiente']);
        }
        return $w;
    }

    private function hecho(Warranty $w, string $paso): bool
    {
        return collect(WarrantySteps::forWarranty($w)[0]['steps'])->firstWhere('id', $paso)['done_at'] !== null;
    }

    public function test_comprobar_en_el_cloud_marca_el_primer_paso(): void
    {
        $w = $this->garantia();
        $this->assertSame('cloud', WarrantySteps::forWarranty($w)[0]['steps'][0]['auto']);
        WarrantySteps::auto($w, 'cloud', null);
        WarrantySteps::auto($w, 'cloud', null);                     // una sola vez
        $this->assertTrue($this->hecho($w, 'g1'));
        $this->assertFalse($this->hecho($w, 'g2'));
        $this->assertSame(1, DB::table('warranty_events')->where('warranty_id', $w->id)->where('field', 'steps')->count());
        $this->assertSame('Automático', WarrantySteps::forWarranty($w)[0]['steps'][0]['done_by']);
    }

    public function test_revisar_todas_marca_el_segundo_paso(): void
    {
        $w = $this->garantia();
        WarrantyItem::where('code', 'A1')->update(['resolution' => 'correcta']);
        WarrantySteps::auto($w, 'revision', null);
        $this->assertFalse($this->hecho($w, 'g2'));                  // falta A2

        WarrantyItem::where('code', 'A2')->update(['resolution' => 'defectuosa']);
        WarrantySteps::auto($w, 'revision', null);
        $this->assertTrue($this->hecho($w, 'g2'));
    }

    public function test_listas_guardadas_antes_conservan_el_automatico_por_su_id(): void
    {
        // Configuración guardada sin la clave «auto» (de antes): g1 y g2 de «Todas» siguen solos.
        $listas = WarrantySteps::DEFAULTS;
        foreach ($listas as &$l) foreach ($l['steps'] as &$s) unset($s['auto']);
        unset($l, $s);
        [$limpias] = WarrantySteps::clean($listas, ['Hoteles']);
        DB::table('warranty_settings')->updateOrInsert(['key' => 'checklists'], ['value' => json_encode(['lists' => $limpias])]);

        $w = $this->garantia();
        $this->assertSame(['cloud', 'revision', null, null], array_column(WarrantySteps::forWarranty($w)[0]['steps'], 'auto'));
    }
}
