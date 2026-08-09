<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    /**
     * Confirm that the application can serve requests.
     *
     * This endpoint is unauthenticated. The payload must stay a bare
     * status flag and must never disclose runtime, dependency, or
     * configuration details.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
        ]);
    }
}
