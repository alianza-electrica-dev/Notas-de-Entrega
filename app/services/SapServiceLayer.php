<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class SapServiceLayer
{
    protected $host;
    protected $username;
    protected $password;

    public function __construct()
    {
        $this->host = config('services.sap.host');
        $this->username = config('services.sap.username');
        $this->password = config('services.sap.password');
    }

    public function login($companyDb)
    {
        if (session()->has('sessionId')) {
            $this->logout();
        }

        $response = Http::withOptions(['verify' => false])
            ->post("{$this->host}/b1s/v1/Login", [
                'CompanyDB' => $companyDb,
                'UserName'  => $this->username,
                'Password'  => $this->password,
            ]);

        if ($response->successful()) {
            session([
                'sessionId' => $response->json()['SessionId'],
                'companyDb' => $companyDb
            ]);

            return response()->json([
                'message'   => 'Login successful',
                'company'   => $companyDb,
                'sessionId' => session('sessionId')
            ]);
        }

        return response()->json([
            'error'     => 'Login failed in SAP',
            'companyDb' => $companyDb,
            'status'    => $response->status(),
            'sap_error' => $response->json()
        ], 401);
    }

    public function logout()
    {
        if (!session()->has('sessionId')) {
            return response()->json(['message' => 'No active session'], 200);
        }

        $response = Http::withOptions(['verify' => false])
            ->withHeaders(['Cookie' => "B1SESSION=" . session('sessionId')])
            ->post("{$this->host}/b1s/v1/Logout");

        session()->flush();

        return $response->successful()
            ? response()->json(['message' => 'Successful logout'])
            : response()->json(['error' => 'Error when logging out of SAP'], 500);
    }
}
