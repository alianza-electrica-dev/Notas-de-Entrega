<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransferInvoicesController extends Controller
{
    private function getInvoiceSeries($company, $deliverySeries)
    {
        $seriesMapping = [
            'SBO_Alianza' => [
                105 => 224,
                180 => 250,
                53 => 215,
                54 => 217,
                55 => 206,
                75 => 222,
                74 => 219,
            ],
            'SBO_Pruebas' => [
                105 => 224,
                180 => 250,
                53 => 215,
                54 => 217,
                55 => 206,
                75 => 222,
                74 => 219,
            ],
            'SBO_FGE' => [
                112 => 146,
            ],
            'Pruebas_FGE' => [
                112 => 146,
            ],
            'SBO_MANUFACTURING' => [
                7 => 89,
                64 => 89,
            ],
            'Pruebas_FGM' => [
                7 => 89,
                64 => 89,
            ],
        ];

        return $seriesMapping[$company][$deliverySeries] ?? null;
    }

    public function autoTransferInvoices()
    {
        // Limpiar logs antes de comenzar
        file_put_contents(storage_path('logs/laravel.log'), '');
        try {
            //$companies = ['SBO_Pruebas', 'SBO_Alianza', 'Pruebas_FGE', 'SBO_FGE'];
            $companies = ['SBO_Alianza', 'SBO_FGE', 'Pruebas_FGM'];

            foreach ($companies as $company) {
                Log::info("Iniciando proceso para la empresa: {$company}");

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

                // Obtener hasta 10 notas de entrega abiertas
                $response = Http::withOptions(['verify' => false])
                    ->withHeaders(['Cookie' => "B1SESSION={$sessionId}"])
                    ->get(config('services.sap.host') . "/b1s/v1/DeliveryNotes?\$filter=DocumentStatus eq 'bost_Open' and U_Auto_Auditoria eq 'N'&\$top=5");

                if (!$response->successful()) {
                    Log::error("Error obteniendo notas de entrega para {$company}", ['response' => $response->json()]);
                    continue;
                }

                $deliveryNotes = $response->json()['value'] ?? [];
                $totalNotas = count($deliveryNotes);

                if ($totalNotas == 0) {
                    Log::info("No hay notas de entrega abiertas en {$company} para procesar.");
                    continue;
                }

                Log::info("Enviando notas a facturas en la empresa {$company}. Notas: " . implode(', ', array_map(fn($note) => $note['DocEntry'], $deliveryNotes)));

                $notasEnviadas = 0;
                $notasRechazadas = 0;
                $rechazadasMotivos = [];

                foreach ($deliveryNotes as $index => $deliveryNote) {
                    $deliveryDocEntry = $deliveryNote['DocEntry'];
                    $deliverySeries = $deliveryNote['Series'];
                    $invoiceSeries = $this->getInvoiceSeries($company, $deliverySeries) ?? $deliverySeries;

                    $invoiceData = [
                        'CardCode'      => $deliveryNote['CardCode'],
                        'DocDate'       => date('Y-m-d'),
                        'Comments'      => $deliveryNote['Comments'] ?? '',
                        'Series'        => $invoiceSeries,
                        'DocCurrency'   => $deliveryNote['DocCurrency'],
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
                        $notasEnviadas++;
                    } else {
                        $errorResponse = $invoiceResponse->json();
                        $errorMessage = $errorResponse['error']['message']['value'] ?? 'Error desconocido';

                        Log::error("Error creando la factura en {$company} para DocEntry: {$deliveryDocEntry} | Motivo: {$errorMessage}");

                        $notasRechazadas++;
                        $rechazadasMotivos[] = "Nota {$deliveryDocEntry}: {$errorMessage}";
                    }
                }

                $pendientes = max(0, $totalNotas - $notasEnviadas - $notasRechazadas);

                Log::info("Proceso finalizado en {$company}. Enviadas: {$notasEnviadas}, Pendientes: {$pendientes}, Rechazadas: {$notasRechazadas}");

                if ($notasRechazadas > 0) {
                    Log::warning("Notas rechazadas en {$company}: " . implode(' | ', $rechazadasMotivos));
                }

                Http::withOptions(['verify' => false])
                    ->withHeaders(['Cookie' => "B1SESSION={$sessionId}"])
                    ->post(config('services.sap.host') . '/b1s/v1/Logout');
            }
        } catch (\Exception $e) {
            Log::error("Excepción en la transferencia de facturas: " . $e->getMessage());
        }
    }
}
