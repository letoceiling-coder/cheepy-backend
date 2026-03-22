<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Payment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public endpoint for payment return pages to verify status.
 * Requires return_token in query — user is redirected from bank with payment_id and return_token in URL.
 */
class PaymentStatusController extends Controller
{
    public function show(Request $request, int $id): JsonResponse
    {
        $returnToken = (string) ($request->query('return_token') ?? '');

        $payment = Payment::find($id);

        if (!$payment) {
            return response()->json(['error' => 'Payment not found'], 404);
        }

        if ($returnToken === '' || $payment->return_token === null || !hash_equals((string) $payment->return_token, $returnToken)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json([
            'id' => $payment->id,
            'status' => $payment->status,
            'amount' => (float) $payment->amount,
            'provider' => $payment->provider,
        ]);
    }
}
