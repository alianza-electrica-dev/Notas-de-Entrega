<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Session;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void { }

    public function boot(): void
    {
        $this->autoLogin();

        Http::macro('sapSL', function () {
            $sessionId = session('sessionId');

            if (!$sessionId) {
                throw new \Exception('There is no active session in SAP');
            }

            $host = config('services.sap.host');

            return Http::withOptions(['verify' => false])
                ->withHeaders(['Cookie' => "B1SESSION={$sessionId}"])
                ->baseUrl("{$host}/b1s/v1");
        });
    }

    protected function autoLogin()
    {
        $request = request();
        $empresaEnUrl = $request->segment(2) ?? 'SBO_Pruebas'; // Obtiene la empresa o usa la predeterminada
        $empresaActual = session('companyDb');

        // Si la empresa ha cambiado, cerrar sesión y reiniciar
        if (!$empresaActual || $empresaEnUrl !== $empresaActual) {
            $this->logoutFromSAP(); // Asegura cerrar sesión antes de cambiar de empresa

            $response = Http::withOptions(['verify' => false])
                ->post(config('services.sap.host') . '/b1s/v1/Login', [
                    'CompanyDB' => $empresaEnUrl,
                    'UserName'  => config('services.sap.username'),
                    'Password'  => config('services.sap.password'),
                ]);

            if ($response->successful()) {
                session([
                    'sessionId' => $response->json()['SessionId'],
                    'companyDb' => $empresaEnUrl
                ]);
            } else {
                session()->forget(['sessionId', 'companyDb']); // Limpiar sesión si el login falla
            }
        }
    }

    protected function logoutFromSAP()
    {
        if (session()->has('sessionId')) {
            Http::withOptions(['verify' => false])
                ->post(config('services.sap.host') . '/b1s/v1/Logout');
            session()->flush(); // Limpiar la sesión completamente
        }
    }
}
