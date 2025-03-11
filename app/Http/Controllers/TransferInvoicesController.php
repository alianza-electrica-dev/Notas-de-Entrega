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
            'SBO_FGE' => [
                112 => 146,
            ],
            'SBO_MANUFACTURING' => [
                7 => 89,
                64 => 89,
            ],
        ];

        return $seriesMapping[$company][$deliverySeries] ?? null;
    }

    public function autoTransferInvoices()
    {
        file_put_contents(storage_path('logs/laravel.log'), '');

        try {
            $companies = ['SBO_Alianza', 'SBO_FGE', 'SBO_MANUFACTURING'];

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
                $totalEnviadas = 0;

                try {
                    $response = Http::withOptions(['verify' => false])
                        ->withHeaders(['Cookie' => "B1SESSION={$sessionId}"])
                        ->get(config('services.sap.host') . "/b1s/v1/DeliveryNotes?\$filter=DocumentStatus eq 'bost_Open' and U_Auto_Auditoria eq 'N'&\$top=1");

                    if (!$response->successful()) {
                        Log::error("Error obteniendo notas de entrega para {$company}", ['response' => $response->json()]);
                        continue;
                    }

                    $deliveryNotes = $response->json()['value'] ?? [];

                    if (empty($deliveryNotes)) {
                        Log::info("No hay notas de entrega abiertas en {$company} para procesar.");
                        continue;
                    }

                    foreach ($deliveryNotes as $deliveryNote) {
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
                            $totalEnviadas++;
                        } else {
                            $errorResponse = $invoiceResponse->json();
                            $errorMessage = $errorResponse['error']['message']['value'] ?? 'Error desconocido';
                            Log::error("Error creando la factura en {$company} para DocEntry: {$deliveryDocEntry} | Motivo: {$errorMessage}");

                            // Validación de clientes "Públicos en General"
                            if (str_contains(strtolower($deliveryNote['CardCode']), 'publico')) {
                                Log::warning("Error en cliente 'Público en General' en {$company}, DocEntry: {$deliveryDocEntry} | Motivo: {$errorMessage}");
                            }
                        }
                    }

                    // Log final con el total de facturas enviadas por empresa
                    Log::info("Proceso finalizado en {$company}. Total facturas enviadas: {$totalEnviadas}");
                } catch (\Exception $e) {
                    Log::error("Excepción en el procesamiento de notas para {$company}: " . $e->getMessage());
                } finally {
                    Http::withOptions(['verify' => false])
                        ->withHeaders(['Cookie' => "B1SESSION={$sessionId}"])
                        ->post(config('services.sap.host') . '/b1s/v1/Logout');
                }
            }
        } catch (\Exception $e) {
            Log::error("Excepción en la transferencia de facturas: " . $e->getMessage());
        }
    }
}
