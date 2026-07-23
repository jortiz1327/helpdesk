<?php

namespace App\Http\Controllers;

use App\Services\GatingService;

/** Portado de api/gating.php — estado de los candados para la interfaz. */
class GatingController extends Controller
{
    public function handle()
    {
        $verified = GatingService::accountVerified();
        return response()->json([
            'ok'       => true,
            'verified' => $verified,
            'features' => $verified ? (object) [] : GatingService::features(),
        ]);
    }
}
