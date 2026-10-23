<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicyReconciliationController extends Controller
{
    public function __construct(
        protected IssuanceService $issuanceService
    ) {}

    /**
     * Reconcile an ambiguous or timed-out request by querying mock insurer with stable reference (Case B).
     */
    public function reconcile(Request $request, string $id): JsonResponse
    {
        $policy = PolicyRequest::find($id);

        if (! $policy) {
            return response()->json([
                'error' => 'NOT_FOUND',
                'message' => "Policy request '{$id}' does not exist.",
            ], 404);
        }

        $result = $this->issuanceService->reconcile($policy);

        return response()->json([
            'status' => $result['recovered'] ?? false ? 'RECONCILED_ISSUED' : 'RECONCILED',
            'message' => $result['message'],
            'recovered_policy_number' => $result['policy_number'] ?? null,
            'data' => $result['policy'],
        ], 200);
    }
}
