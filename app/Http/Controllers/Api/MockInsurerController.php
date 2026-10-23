<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MockInsurerCallbackRequest;
use App\Models\MockProviderPolicy;
use App\Services\IssuanceService;
use Illuminate\Http\JsonResponse;

class MockInsurerController extends Controller
{
    public function __construct(
        protected IssuanceService $issuanceService
    ) {}

    /**
     * Process a simulated provider event idempotently.
     */
    public function callback(MockInsurerCallbackRequest $request): JsonResponse
    {
        $result = $this->issuanceService->processCallback($request->validated());

        return response()->json($result, 200);
    }

    /**
     * Public mock insurer status lookup endpoint for reviewer inspection.
     */
    public function status(string $reference): JsonResponse
    {
        $record = MockProviderPolicy::where('provider_reference', $reference)
            ->orWhere('idempotency_key', $reference)
            ->first();

        if (! $record) {
            return response()->json([
                'status' => 'NOT_FOUND',
                'message' => "Mock insurer has no record with reference '{$reference}'.",
            ], 404);
        }

        return response()->json([
            'status' => 'FOUND',
            'data' => $record,
        ], 200);
    }
}
