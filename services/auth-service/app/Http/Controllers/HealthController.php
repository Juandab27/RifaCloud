<?php

namespace RifaCloud\AuthService\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        try {
            DB::select('SELECT 1');
        } catch (\Throwable) {
            return response()->json([
                'status' => 'unhealthy',
                'service' => 'auth-service',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'service' => 'auth-service',
        ]);
    }
}
