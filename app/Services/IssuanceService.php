<?php

namespace App\Services;

use App\Enums\MockScenario;
use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Exceptions\ProviderTemporaryException;
use App\Exceptions\ProviderTimeoutException;
use App\Models\IssuanceAttempt;
use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IssuanceService
{
    public function __construct(
        protected PolicyStateMachine $stateMachine,
        protected MockInsurerService $mockInsurer
    ) {}

    /**
     * Submit a policy request for issuance under serialized locking.
     *
     * @throws InvalidStateTransitionException
     * @throws ProviderTimeoutException
     * @throws ProviderTemporaryException
     */
    public function submit(
        PolicyRequest $policy,
        string|MockScenario $scenario = MockScenario::SUCCESS
    ): PolicyRequest {
        // Step 1: Pre-submission state check and lock
        $prep = DB::transaction(function () use ($policy, $scenario) {
            /** @var PolicyRequest $lockedPolicy */
            $lockedPolicy = PolicyRequest::where('id', $policy->id)->lockForUpdate()->firstOrFail();

            if ($lockedPolicy->isIssued()) {
                throw new InvalidStateTransitionException(
                    fromState: PolicyStatus::ISSUED->value,
                    toState: PolicyStatus::SUBMITTED->value,
                    policyId: $lockedPolicy->id,
                    customReason: 'Policy has already been issued and cannot be re-submitted.'
                );
            }

            $attemptNumber = $lockedPolicy->attempt_count + 1;
            $idempotencyKey = "idemp_{$lockedPolicy->id}_att{$attemptNumber}";
            $scenarioName = $scenario instanceof MockScenario ? $scenario->value : (string) $scenario;

            $this->stateMachine->transition(
                policy: $lockedPolicy,
                toState: PolicyStatus::SUBMITTED,
                reason: "Submission attempt #{$attemptNumber} initiated (scenario: {$scenarioName})",
                metadata: [
                    'attempt' => $attemptNumber,
                    'scenario' => $scenarioName,
                    'idempotency_key' => $idempotencyKey,
                ]
            );

            return [
                'policy' => $lockedPolicy,
                'attemptNumber' => $attemptNumber,
                'idempotencyKey' => $idempotencyKey,
                'scenarioName' => $scenarioName,
            ];
        });

        $lockedPolicy = $prep['policy'];
        $attemptNumber = $prep['attemptNumber'];
        $idempotencyKey = $prep['idempotencyKey'];
        $scenarioName = $prep['scenarioName'];

        // Step 2: Call mock insurer outside open database transaction (network isolation best practice)
        try {
            $result = $this->mockInsurer->issue(
                policy: $lockedPolicy,
                scenario: $scenario,
                idempotencyKey: $idempotencyKey
            );

            return DB::transaction(function () use ($lockedPolicy, $result, $attemptNumber, $idempotencyKey, $scenarioName) {
                $fresh = PolicyRequest::where('id', $lockedPolicy->id)->lockForUpdate()->firstOrFail();

                if (($result['status'] ?? '') === 'ISSUED') {
                    $fresh->policy_number = $result['policy_number'];
                    $fresh->provider_reference = $result['provider_reference'];
                    $fresh->attempt_count = $attemptNumber;
                    $fresh->last_response = $result;
                    $fresh->save();

                    $this->stateMachine->transition(
                        policy: $fresh,
                        toState: PolicyStatus::ISSUED,
                        reason: "Provider issued policy with number {$result['policy_number']}",
                        metadata: $result
                    );

                    IssuanceAttempt::create([
                        'id' => (string) Str::uuid(),
                        'policy_request_id' => $fresh->id,
                        'attempt_number' => $attemptNumber,
                        'idempotency_key' => $idempotencyKey,
                        'provider_reference' => $result['provider_reference'],
                        'scenario' => $scenarioName,
                        'request_payload' => ['scenario' => $scenarioName],
                        'response_payload' => $result,
                        'status' => 'SUCCESS',
                    ]);

                    return $fresh;
                }

                if (($result['status'] ?? '') === 'PENDING') {
                    $fresh->provider_reference = $result['provider_reference'];
                    $fresh->attempt_count = $attemptNumber;
                    $fresh->last_response = $result;
                    $fresh->save();

                    $this->stateMachine->transition(
                        policy: $fresh,
                        toState: PolicyStatus::PENDING_CONFIRMATION,
                        reason: 'Provider accepted submission asynchronously; awaiting callback',
                        metadata: $result
                    );

                    IssuanceAttempt::create([
                        'id' => (string) Str::uuid(),
                        'policy_request_id' => $fresh->id,
                        'attempt_number' => $attemptNumber,
                        'idempotency_key' => $idempotencyKey,
                        'provider_reference' => $result['provider_reference'],
                        'scenario' => $scenarioName,
                        'request_payload' => ['scenario' => $scenarioName],
                        'response_payload' => $result,
                        'status' => 'PENDING',
                    ]);

                    return $fresh;
                }

                return $fresh;
            });
        } catch (ProviderTimeoutException $e) {
            DB::transaction(function () use ($lockedPolicy, $e, $attemptNumber, $idempotencyKey, $scenarioName) {
                $fresh = PolicyRequest::where('id', $lockedPolicy->id)->lockForUpdate()->firstOrFail();
                $fresh->attempt_count = $attemptNumber;
                if ($e->providerReference) {
                    $fresh->provider_reference = $e->providerReference;
                }
                $fresh->last_response = ['error' => 'TIMEOUT', 'message' => $e->getMessage()];
                $fresh->save();

                $this->stateMachine->transition(
                    policy: $fresh,
                    toState: PolicyStatus::NEEDS_RECONCILIATION,
                    reason: 'Provider timeout during issuance; marked for reconciliation to prevent duplicate issuance',
                    metadata: ['error' => $e->getMessage(), 'provider_reference' => $e->providerReference]
                );

                IssuanceAttempt::create([
                    'id' => (string) Str::uuid(),
                    'policy_request_id' => $fresh->id,
                    'attempt_number' => $attemptNumber,
                    'idempotency_key' => $idempotencyKey,
                    'provider_reference' => $e->providerReference,
                    'scenario' => $scenarioName,
                    'request_payload' => ['scenario' => $scenarioName],
                    'response_payload' => null,
                    'status' => 'TIMEOUT',
                    'error_message' => $e->getMessage(),
                ]);
            });

            throw $e;
        } catch (ProviderTemporaryException $e) {
            DB::transaction(function () use ($lockedPolicy, $e, $attemptNumber, $idempotencyKey, $scenarioName) {
                $fresh = PolicyRequest::where('id', $lockedPolicy->id)->lockForUpdate()->firstOrFail();
                $fresh->attempt_count = $attemptNumber;
                $fresh->last_response = ['error' => 'TEMPORARY_FAILURE', 'message' => $e->getMessage()];
                $fresh->save();

                $this->stateMachine->transition(
                    policy: $fresh,
                    toState: PolicyStatus::FAILED,
                    reason: "Provider temporary failure: {$e->getMessage()}",
                    metadata: ['error' => $e->getMessage()]
                );

                IssuanceAttempt::create([
                    'id' => (string) Str::uuid(),
                    'policy_request_id' => $fresh->id,
                    'attempt_number' => $attemptNumber,
                    'idempotency_key' => $idempotencyKey,
                    'provider_reference' => null,
                    'scenario' => $scenarioName,
                    'request_payload' => ['scenario' => $scenarioName],
                    'response_payload' => null,
                    'status' => 'FAILED',
                    'error_message' => $e->getMessage(),
                ]);
            });

            throw $e;
        }
    }

    /**
     * Reconcile an ambiguous or timed-out request by querying the mock insurer.
     */
    public function reconcile(PolicyRequest $policy): array
    {
        return DB::transaction(function () use ($policy) {
            /** @var PolicyRequest $lockedPolicy */
            $lockedPolicy = PolicyRequest::where('id', $policy->id)->lockForUpdate()->firstOrFail();

            if ($lockedPolicy->isIssued()) {
                return [
                    'reconciled' => true,
                    'already_issued' => true,
                    'policy' => $lockedPolicy,
                    'message' => 'Policy is already in terminal ISSUED state.',
                ];
            }

            // Stable idempotency key from latest attempt
            $latestAttempt = $lockedPolicy->attempts()->latest()->first();
            $idempotencyKey = $latestAttempt?->idempotency_key;

            $statusCheck = $this->mockInsurer->checkStatus(
                providerReference: $lockedPolicy->provider_reference,
                idempotencyKey: $idempotencyKey
            );

            if (($statusCheck['found'] ?? false) && ($statusCheck['status'] ?? '') === 'ISSUED') {
                $lockedPolicy->policy_number = $statusCheck['policy_number'];
                $lockedPolicy->provider_reference = $statusCheck['provider_reference'];
                $lockedPolicy->save();

                $this->stateMachine->transition(
                    policy: $lockedPolicy,
                    toState: PolicyStatus::ISSUED,
                    reason: "Reconciliation recovered existing policy [{$statusCheck['policy_number']}] from mock insurer without reissuance",
                    metadata: $statusCheck
                );

                return [
                    'reconciled' => true,
                    'recovered' => true,
                    'policy' => $lockedPolicy,
                    'policy_number' => $lockedPolicy->policy_number,
                    'message' => 'Successfully reconciled with provider. Existing policy recovered.',
                ];
            }

            // Insurer confirms no policy was created
            $this->stateMachine->transition(
                policy: $lockedPolicy,
                toState: PolicyStatus::FAILED,
                reason: 'Reconciliation check confirmed no policy was issued at provider',
                metadata: $statusCheck
            );

            return [
                'reconciled' => true,
                'recovered' => false,
                'policy' => $lockedPolicy,
                'message' => 'Provider confirmed no policy was issued. Request transitioned to FAILED and is eligible for safe retry.',
            ];
        });
    }

    /**
     * Ingest and idempotently process an asynchronous provider callback.
     */
    public function processCallback(array $payload): array
    {
        return DB::transaction(function () use ($payload) {
            $eventId = $payload['event_id'] ?? null;
            $providerReference = $payload['provider_reference'] ?? null;
            $eventType = $payload['event_type'] ?? 'POLICY_ISSUED';
            $policyNumber = $payload['policy_number'] ?? null;

            if (! $eventId) {
                return [
                    'success' => false,
                    'message' => 'Missing event_id in callback payload',
                ];
            }

            // Database deduplication check
            $existingEvent = ProviderEvent::where('event_id', $eventId)->first();
            if ($existingEvent) {
                return [
                    'success' => true,
                    'duplicate' => true,
                    'status' => 'DUPLICATE_IGNORED',
                    'message' => "Event '{$eventId}' has already been processed.",
                    'event_id' => $eventId,
                ];
            }

            /** @var PolicyRequest|null $policy */
            $policy = PolicyRequest::where('provider_reference', $providerReference)->lockForUpdate()->first();

            // Persist provider event record
            $eventRecord = ProviderEvent::create([
                'id' => (string) Str::uuid(),
                'event_id' => $eventId,
                'provider_reference' => $providerReference ?? 'UNKNOWN',
                'policy_request_id' => $policy?->id,
                'event_type' => $eventType,
                'payload' => $payload,
                'status' => 'PROCESSED',
                'processed_at' => now(),
            ]);

            if (! $policy) {
                return [
                    'success' => true,
                    'duplicate' => false,
                    'status' => 'UNMATCHED_POLICY',
                    'message' => "Event recorded, but no matching policy found for reference '{$providerReference}'.",
                    'event' => $eventRecord,
                ];
            }

            // If policy is already ISSUED, do not overwrite or modify
            if ($policy->isIssued()) {
                return [
                    'success' => true,
                    'duplicate' => false,
                    'status' => 'TERMINAL_STATE_PRESERVED',
                    'message' => 'Policy is already in terminal ISSUED state. Callback acknowledged without mutating state.',
                    'policy' => $policy,
                ];
            }

            if ($eventType === 'POLICY_ISSUED') {
                if ($policyNumber) {
                    $policy->policy_number = $policyNumber;
                }
                $policy->save();

                $this->stateMachine->transition(
                    policy: $policy,
                    toState: PolicyStatus::ISSUED,
                    reason: "Callback event [{$eventId}] confirmed issuance with policy number {$policy->policy_number}",
                    metadata: $payload
                );
            } elseif ($eventType === 'POLICY_FAILED') {
                $this->stateMachine->transition(
                    policy: $policy,
                    toState: PolicyStatus::FAILED,
                    reason: "Callback event [{$eventId}] indicated provider failure",
                    metadata: $payload
                );
            }

            return [
                'success' => true,
                'duplicate' => false,
                'status' => 'PROCESSED',
                'policy' => $policy,
                'event_id' => $eventId,
            ];
        });
    }
}
