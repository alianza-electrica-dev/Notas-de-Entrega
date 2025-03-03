<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use App\Services\SapServiceLayer;

class InvoicesController extends Controller
{
    protected $sapService;

    public function __construct(SapServiceLayer $sapService)
    {
        $this->sapService = $sapService;
    }

    public function getOpenInvoices()
    {
        try {
            // Llamada a SAP Service Layer para obtener las facturas abiertas
            $response = Http::sapSL()->get("Invoices?\$filter=DocumentStatus eq 'bost_Open'");

            // Verifica si la respuesta es exitosa
            if ($response->successful()) {
                return response()->json($response->json(), 200);
            } else {
                return response()->json(['error' => 'Error fetching open invoices', 'details' => $response->json()], $response->status());
            }
        } catch (\Exception $e) {
            return response()->json(['error' => 'There is no active session or it has expired', 'details' => $e->getMessage()], 401);
        }
    }
}
