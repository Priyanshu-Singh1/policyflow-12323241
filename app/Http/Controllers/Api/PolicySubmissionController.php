<?php

namespace App\Http\Controllers\Api;

use App\Enums\MockScenario;
use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\ProviderTemporaryException;
use App\Exceptions\ProviderTimeoutException;
use App\Http\Controllers\Controller;
use App\Models\PolicyRequest;
use App\Services\IssuanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PolicySubmissionController extends Controller
{
    public function __construct(
        protected IssuanceService $issuanceService
    ) {}

    /**
     * Start issuance or safely resume an eligible request.
     * Evaluates X-Mock-Scenario header or request parameter.
     */
    public function submit(Request $request, string $id): JsonResponse
    {
        $policy = PolicyRequest::find($id);

        if (! $policy) {
            return response()->json([
                'error' => 'NOT_FOUND',
                'message' => "Policy request '{$id}' does not exist.",
            ], 404);
        }

        // Scenario can be passed via Header (e.g. X-Mock-Scenario) or body/query param
        $rawScenario = $request->header('X-Mock-Scenario')
            ?? $request->input('scenario')
            ?? $request->input('mock_scenario')
            ?? MockScenario::SUCCESS->value;

        $scenario = MockScenario::tryFrom(strtoupper((string) $rawScenario)) ?? MockScenario::SUCCESS;

        try {
            $updated = $this->issuanceService->submit($policy, $scenario);

            if ($updated->status === PolicyStatus::PENDING_CONFIRMATION->value) {
                return response()->json([
                    'status' => 'PENDING_CONFIRMATION',
                    'message' => 'Policy submitted to insurer; awaiting asynchronous callback confirmation.',
                    'data' => $updated,
                ], 202);
            }

            return response()->json([
                'status' => 'ISSUED',
                'message' => 'Policy successfully issued by external insurer.',
                'data' => $updated,
            ], 200);
        } catch (InvalidStateTransitionException $e) {
            return $e->render($request);
        } catch (ProviderTimeoutException $e) {
            return $e->render($request);
        } catch (ProviderTemporaryException $e) {
            return $e->render($request);
        }
    }
}
