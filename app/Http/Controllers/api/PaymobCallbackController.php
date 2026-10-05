<?php

namespace App\Http\Controllers\api;

use App\Http\Controllers\Controller;
use App\trait\paymob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymobCallbackController extends Controller
{
    use paymob;

    /**
     * Handle Paymob payment callback (redirection & webhook).
     */
    public function callback(Request $request): JsonResponse
    {
        return $this->paymobCallback($request);
    }
}
