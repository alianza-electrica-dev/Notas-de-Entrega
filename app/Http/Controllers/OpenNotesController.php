<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use App\Services\SapServiceLayer;

class OpenNotesController extends Controller
{
    protected $sapService;

    public function __construct(SapServiceLayer $sapService)
    {
        $this->sapService = $sapService;
    }

    public function getOpenDeliveryNotes()
    {
        try {
            // Llamada a SAP Service Layer para obtener las notas abiertas
            $response = Http::sapSL()->get("DeliveryNotes?\$filter=DocumentStatus eq 'bost_Open'");

            // Verifica si la respuesta es exitosa
            if ($response->successful()) {
                return response()->json($response->json(), 200);
            } else {
                return response()->json(['error' => 'Error fetching open delivery notes', 'details' => $response->json()], $response->status());
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'There is no active session or it has expired', 'details' => $e->getMessage()], 401);
        }
    }
}
