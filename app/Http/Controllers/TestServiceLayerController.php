<?php

namespace App\Http\Controllers;

use App\Services\SapServiceLayer;

class TestServiceLayerController extends Controller
{
    protected $sapService;
    public function __construct(SapServiceLayer $sapService)
    {
        $this->sapService = $sapService;
    }

    public function login($company)
    {
        $staticCompanies = config('services.sap.static_companies');
        $dynamicCompanies = array_map(fn($c) => 'SBO_' . strtoupper($c), config('services.sap.dynamic_companies'));
        if (!in_array($company, array_merge($staticCompanies, $dynamicCompanies))) {
            return response()->json(['error' => 'Empresa no válida.'], 400);
        }
        return $this->sapService->login($company);
    }

    public function logout()
    {
        return $this->sapService->logout();
    }
}