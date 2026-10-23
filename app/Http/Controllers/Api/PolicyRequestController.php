<?php

namespace App\Http\Controllers\Api;

use App\Enums\Destination;
use App\Enums\PlanCode;
use App\Enums\PolicyStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\CreatePolicyRequest;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;
use App\Services\PremiumCalculator;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PolicyRequestController extends Controller
{
    public function __construct(
        protected PremiumCalculator $calculator
    ) {}

    /**
     * Create a new policy request or safely return an existing one (Idempotent Intake - Case A).
     * Returns HTTP 201 for a new request, HTTP 200 for repeated request.
     */
    public function store(CreatePolicyRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $agentId = $validated['agent_id'];
        $clientRequestId = $validated['client_request_id'];

        // Fast-path lookup for duplicate intake
        $existing = PolicyRequest::where('agent_id', $agentId)
            ->where('client_request_id', $clientRequestId)
            ->first();

        if ($existing) {
            return response()->json([
                'status' => 'EXISTING_REQUEST',
                'message' => 'Safely returned existing policy request without recalculating premium or duplicating records.',
                'data' => $existing,
            ], 200);
        }

        // Calculate deterministic premium snapshot
        $pricing = $this->calculator->calculate(
            dateOfBirth: $validated['date_of_birth'],
            tripStart: $validated['trip_start'],
            tripEnd: $validated['trip_end'],
            planCode: PlanCode::from($validated['plan_code']),
            destination: Destination::from($validated['destination']),
            sumInsured: $validated['sum_insured']
        );

        try {
            $policy = DB::transaction(function () use ($validated, $pricing) {
                $id = (string) Str::uuid();

                $newPolicy = PolicyRequest::create([
                    'id' => $id,
                    'client_request_id' => $validated['client_request_id'],
                    'agent_id' => $validated['agent_id'],
                    'customer_name' => $validated['customer_name'],
                    'date_of_birth' => $validated['date_of_birth'],
                    'destination' => $validated['destination'],
                    'trip_start' => $validated['trip_start'],
                    'trip_end' => $validated['trip_end'],
                    'plan_code' => $validated['plan_code'],
                    'sum_insured' => $validated['sum_insured'],
                    'travel_days' => $pricing['travel_days'],
                    'premium_amount' => $pricing['premium'],
                    'currency' => 'INR',
                    'status' => PolicyStatus::VALIDATED->value,
                    'attempt_count' => 0,
                ]);

                // Initial audit trail
                PolicyStateHistory::create([
                    'policy_request_id' => $id,
                    'from_state' => PolicyStatus::RECEIVED->value,
                    'to_state' => PolicyStatus::VALIDATED->value,
                    'reason' => 'Intake validated; deterministic premium calculated and snapshotted',
                    'metadata' => [
                        'pricing_breakdown' => $pricing['breakdown'],
                    ],
                    'created_at' => now(),
                ]);

                return $newPolicy;
            });

            return response()->json([
                'status' => 'CREATED',
                'message' => 'Policy request successfully created and validated.',
                'data' => $policy,
            ], 201);
        } catch (QueryException $e) {
            // Concurrent race condition protection: DB unique constraint caught
            if ($e->getCode() === '23000' || str_contains($e->getMessage(), 'unique_agent_client_request')) {
                $raceExisting = PolicyRequest::where('agent_id', $agentId)
                    ->where('client_request_id', $clientRequestId)
                    ->firstOrFail();

                return response()->json([
                    'status' => 'EXISTING_REQUEST',
                    'message' => 'Concurrent duplicate intake resolved via database uniqueness constraint. Safely returned existing record.',
                    'data' => $raceExisting,
                ], 200);
            }

            throw $e;
        }
    }

    /**
     * Retrieve status, premium, references, and audit history.
     */
    public function show(string $id): JsonResponse
    {
        $policy = PolicyRequest::with([
            'attempts',
            'stateHistories',
            'events',
        ])->find($id);

        if (! $policy) {
            return response()->json([
                'error' => 'NOT_FOUND',
                'message' => "Policy request with ID '{$id}' does not exist.",
            ], 404);
        }

        return response()->json([
            'status' => 'SUCCESS',
            'data' => $policy,
            'audit_history' => $policy->stateHistories,
            'issuance_attempts' => $policy->attempts,
        ], 200);
    }

    /**
     * List policy requests with status filters and pagination.
     */
    public function index(Request $request): JsonResponse
    {
        $query = PolicyRequest::query();

        if ($request->filled('status')) {
            $query->where('status', strtoupper($request->query('status')));
        }

        if ($request->filled('agent_id')) {
            $query->where('agent_id', $request->query('agent_id'));
        }

        if ($request->filled('destination')) {
            $query->where('destination', strtoupper($request->query('destination')));
        }

        $perPage = min(100, max(1, (int) $request->query('per_page', 15)));
        $policies = $query->orderByDesc('created_at')->paginate($perPage);

        return response()->json([
            'status' => 'SUCCESS',
            'data' => $policies->items(),
            'pagination' => [
                'current_page' => $policies->currentPage(),
                'per_page' => $policies->perPage(),
                'total' => $policies->total(),
                'last_page' => $policies->lastPage(),
            ],
        ], 200);
    }
}
