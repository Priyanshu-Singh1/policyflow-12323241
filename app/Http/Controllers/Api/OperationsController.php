<?php

namespace App\Http\Controllers\Api;

use App\Enums\PolicyStatus;
use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use Illuminate\Http\JsonResponse;

class OperationsController extends Controller
{
    /**
     * Read-only operational metrics summary.
     */
    public function summary(): JsonResponse
    {
        $statusCounts = PolicyRequest::query()
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        $issuedCount = $statusCounts[PolicyStatus::ISSUED->value] ?? 0;
        $pendingCount = $statusCounts[PolicyStatus::PENDING_CONFIRMATION->value] ?? 0;
        $needsReconciliationCount = $statusCounts[PolicyStatus::NEEDS_RECONCILIATION->value] ?? 0;
        $failedCount = $statusCounts[PolicyStatus::FAILED->value] ?? 0;
        $validatedCount = $statusCounts[PolicyStatus::VALIDATED->value] ?? 0;

        $totalIssuedPremium = (float) PolicyRequest::where('status', PolicyStatus::ISSUED->value)
            ->sum('premium_amount');

        $reconciliationList = PolicyRequest::where('status', PolicyStatus::NEEDS_RECONCILIATION->value)
            ->select('id', 'client_request_id', 'agent_id', 'provider_reference', 'attempt_count', 'updated_at')
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();

        return response()->json([
            'status' => 'SUCCESS',
            'summary' => [
                'total_requests' => PolicyRequest::count(),
                'issued_policies' => $issuedCount,
                'pending_confirmations' => $pendingCount,
                'needs_reconciliation' => $needsReconciliationCount,
                'failed_requests' => $failedCount,
                'validated_unsubmitted' => $validatedCount,
                'total_premium_issued_inr' => round($totalIssuedPremium, 2),
            ],
            'needs_reconciliation_items' => $reconciliationList,
        ], 200);
    }
}
