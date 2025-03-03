<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransferInvoices extends Command
{
    protected $signature = 'invoices:transfer';
    protected $description = 'Transfers open delivery notes to debtor invoices every 10 minutes';

    public function handle()
    {
        try {
            // Obtener las primeras 10 notas de entrega abiertas
            $response = Http::sapSL()->get("DeliveryNotes?\$filter=DocumentStatus eq 'bost_Open'&\$top=11");

            if (!$response->successful()) {
                Log::error('Error fetching open delivery notes', ['response' => $response->json()]);
                return;
            }

            $deliveryNotes = $response->json()['value'] ?? [];

            if (empty($deliveryNotes)) {
                Log::info('No open delivery notes found.');
                return;
            }

            // **Mapeo de series (SE -> SF)**
            $seriesMapping = [
                105 => 224, 180 => 250, 53 => 215, 54 => 217,
                55 => 206,  75 => 222, 74 => 219,
            ];

            $logFile = storage_path('logs/transfer_log.txt'); // Archivo donde guardaremos el registro

            foreach ($deliveryNotes as $deliveryNote) {
                $deliveryDocEntry = $deliveryNote['DocEntry'];
                $deliverySeries = $deliveryNote['Series'];

                // Determinar la serie de la factura
                $invoiceSeries = $seriesMapping[$deliverySeries] ?? null;
                if (!$invoiceSeries) {
                    Log::warning("No matching invoice series for delivery series {$deliverySeries}");
                    continue;
                }

                // **Obtener la fecha de ayer en formato YYYY-MM-DD**
               // $previousDate = date('Y-m-d', strtotime('-1 day'));
               $currentDate = date('Y-m-d');

                // **Datos para la factura**
                $invoiceData = [
                    'CardCode'       => $deliveryNote['CardCode'],
                    'DocDate' => $currentDate, // Se usa la fecha actual
                    //'DocDate'        => $previousDate,
                    'Comments'       => $deliveryNote['Comments'] ?? '',
                    'DocTotal'       => $deliveryNote['DocTotal'] ?? 0,
                    'Series'         => $invoiceSeries,
                    'DocumentLines'  => []
                ];

                // Validar que existan líneas de producto
                if (empty($deliveryNote['DocumentLines'])) {
                    Log::warning("Delivery note {$deliveryDocEntry} has no lines.");
                    continue;
                }

                // **Transferir las líneas del documento**
                foreach ($deliveryNote['DocumentLines'] as $line) {
                    $invoiceData['DocumentLines'][] = [
                        'ItemCode'      => $line['ItemCode'],
                        'Quantity'      => $line['Quantity'],
                        'Price'         => $line['Price'],
                        'TaxCode'       => $line['TaxCode'] ?? 'IVA',
                        'WarehouseCode' => $line['WarehouseCode'] ?? '01',
                        'BaseEntry'     => $deliveryDocEntry,
                        'BaseType'      => 15,
                        'BaseLine'      => $line['LineNum']
                    ];
                }

                // Enviar la Nota de Entrega como Factura
                $invoiceResponse = Http::sapSL()->post('Invoices', $invoiceData);

                if ($invoiceResponse->successful()) {
                    $logMessage = "Transfer successful | CardCode: {$deliveryNote['CardCode']} | CardName: {$deliveryNote['CardName']} | DocEntry: {$deliveryDocEntry} | Date: " . now()->toDateTimeString() . "\n";
                    file_put_contents($logFile, $logMessage, FILE_APPEND); // Escribir en el archivo
                    Log::info($logMessage);
                } else {
                    Log::error("Error creating invoice for {$deliveryNote['CardCode']}", ['response' => $invoiceResponse->json()]);
                }
            }
        } catch (\Exception $e) {
            Log::error("Exception in invoice transfer: " . $e->getMessage());
        }
    }
}