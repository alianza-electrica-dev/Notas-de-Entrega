<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TestServiceLayerController;
use App\Http\Controllers\TestServiceLayerMacroController;
use App\Http\Controllers\OpenNotesController;
use App\Http\Controllers\InvoicesController;
use App\Http\Controllers\TransferInvoicesController;


Route::get('/', function () {
    return view('welcome');
});



Route::get('/', function () {
    return view('welcome');
});

Route::get('/login/{company}', [TestServiceLayerController::class, 'login']);
Route::get('/providers', [TestServiceLayerController::class, 'getProviders']);
Route::get('/macro/login/{company}', [TestServiceLayerMacroController::class, 'loginMacro']);
Route::get('/providers-macro', [TestServiceLayerMacroController::class, 'getProvidersMacro']);
Route::get('/logout', [TestServiceLayerController::class, 'logout']); 
Route::get('/logout-macro', [TestServiceLayerMacroController::class, 'logoutMacro']); 
Route::get('/open-delivery-notes', [OpenNotesController::class, 'getOpenDeliveryNotes']);
Route::get('/open-invoices', [InvoicesController::class, 'getOpenInvoices']);
//Route::get('/transfer-invoices/{identifier}', [TransferInvoicesController::class, 'transferToInvoice']);
Route::get('/transfer-invoices', [TransferInvoicesController::class, 'autoTransferInvoices']);