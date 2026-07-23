<?php

namespace App\Http\Controllers;

use App\Services\GatingService;

/** Portado de api/gating.php — estado de los candados para la interfaz. */
class GatingController extends Controller
{
    public function handle()
    {
        // features() ya refleja los candados vigentes (verificación de Meta Y config de
        // WhatsApp), así que se devuelve siempre; se castea a objeto para que en JSON
        // sea `{}` y no `[]` cuando está vacío (la UI hace gate.features?.clave).
        return response()->json([
            'ok'            => true,
            'verified'      => GatingService::accountVerified(),
            'wa_configured' => GatingService::whatsappConfigured(),
            'features'      => (object) GatingService::features(),
        ]);
    }
}
