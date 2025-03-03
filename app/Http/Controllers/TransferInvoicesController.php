<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransferInvoicesController extends Controller
{
    public function autoTransferInvoices()
    {
        try {
            $companies = ['SBO_Pruebas' /*'SBO_Alianza', 'SBO_FGE', 'SBO_MANUFACTURING'*/];

            foreach ($companies as $company) {
                Log::info("Iniciando proceso para la empresa: {$company}");
                
                // Iniciar sesión en SAP B1
                $loginResponse = Http::withOptions(['verify' => false])->post(config('services.sap.host') . '/b1s/v1/Login', [
                    'CompanyDB' => $company,
                    'UserName'  => config('services.sap.username'),
                    'Password'  => config('services.sap.password'),
                ]);
                
                if (!$loginResponse->successful()) {
                    Log::error("Error al iniciar sesión en SAP B1 para la empresa {$company}", ['response' => $loginResponse->json()]);
                    continue;
                }
                
                $sessionId = $loginResponse->json()['SessionId'];

                // Obtener notas de entrega en lotes de 10
                $page = 1;
                do {
                    Log::info("Mandando lote {$page} de 10 en la empresa: {$company}");
                    
                    $response = Http::withOptions(['verify' => false])
                        ->withHeaders(['Cookie' => "B1SESSION={$sessionId}"])
                        ->get(config('services.sap.host') . "/b1s/v1/DeliveryNotes?\$filter=DocumentStatus eq 'bost_Open'&\$top=10");

                    if (!$response->successful()) {
                        Log::error("Error obteniendo notas de entrega para {$company}", ['response' => $response->json()]);
                        break;
                    }

                    $deliveryNotes = $response->json()['value'] ?? [];
                    if (empty($deliveryNotes)) {
                        Log::info("No hay más notas de entrega abiertas en {$company}.");
                        break;
                    }

                    foreach ($deliveryNotes as $index => $deliveryNote) {
                        Log::info("Procesando nota de entrega " . ($index + 1) . " en el lote {$page} para {$company}");
                        
                        $deliveryDocEntry = $deliveryNote['DocEntry'];
                        
                        $invoiceData = [
                            'CardCode'      => $deliveryNote['CardCode'],
                            'DocDate'       => date('Y-m-d'),
                            'Comments'      => $deliveryNote['Comments'] ?? '',
                            'DocTotal'      => $deliveryNote['DocTotal'] ?? 0,
                            'DocumentLines' => []
                        ];

                        foreach ($deliveryNote['DocumentLines'] as $line) {
                            $invoiceData['DocumentLines'][] = [
                                'ItemCode'      => $line['ItemCode'],
                                'Quantity'      => $line['Quantity'],
                                'Price'         => $line['Price'],
                                'WarehouseCode' => $line['WarehouseCode'] ?? '01',
                                'BaseEntry'     => $deliveryDocEntry,
                                'BaseType'      => 15,
                                'BaseLine'      => $line['LineNum']
                            ];
                        }
                        
                        $invoiceResponse = Http::withOptions(['verify' => false])
                            ->withHeaders(['Cookie' => "B1SESSION={$sessionId}"])
                            ->post(config('services.sap.host') . "/b1s/v1/Invoices", $invoiceData);

                        if ($invoiceResponse->successful()) {
                            Log::info("Factura creada en {$company} para CardCode: {$deliveryNote['CardCode']} | DocEntry: {$deliveryDocEntry}");
                        } else {
                            Log::error("Error creando la factura en {$company} para {$deliveryNote['CardCode']}", ['response' => $invoiceResponse->json()]);
                        }
                    }

                    $page++;
                } while (count($deliveryNotes) == 10);
                
                // Cerrar sesión en SAP B1
                Http::withOptions(['verify' => false])
                    ->withHeaders(['Cookie' => "B1SESSION={$sessionId}"])
                    ->post(config('services.sap.host') . '/b1s/v1/Logout');

                Log::info("Finalizado el proceso para la empresa: {$company}");
            }
        } catch (\Exception $e) {
            Log::error("Excepción en la transferencia de facturas: " . $e->getMessage());
        }
    }
}
