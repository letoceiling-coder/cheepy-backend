<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\StorefrontSmsGate;
use Illuminate\Http\JsonResponse;

class SmsAuthPublicController extends Controller
{
    public function meta(): JsonResponse
    {
        return response()->json([
            'phone_auth_enabled' => StorefrontSmsGate::phoneAuthEnabled(),
        ]);
    }
}
