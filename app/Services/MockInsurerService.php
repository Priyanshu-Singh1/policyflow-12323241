<?php

namespace App\Services;

use App\Enums\MockScenario;
use App\Exceptions\ProviderTemporaryException;
use App\Exceptions\ProviderTimeoutException;
use App\Models\MockProviderPolicy;
use App\Models\PolicyRequest;
use Illuminate\Support\Str;

class MockInsurerService
{
    /**
     * Issue policy to simulated insurer with deterministic scenarios.
     *
     * @throws ProviderTimeoutException
     * @throws ProviderTemporaryException
     */
    public function issue(
        PolicyRequest $policy,
        string|MockScenario $scenario = MockScenario::SUCCESS,
        ?string $idempotencyKey = null
    ): array {
        $scenarioEnum = $scenario instanceof MockScenario
            ? $scenario
            : MockScenario::tryFrom(strtoupper((string) $scenario)) ?? MockScenario::SUCCESS;

        $key = $idempotencyKey ?? "idemp_{$policy->id}_{$policy->attempt_count}";
        $providerRef = 'REF-' . strtoupper(Str::random(10));
        $policyNumber = 'POL-' . strtoupper(Str::random(8));

        switch ($scenarioEnum) {
            case MockScenario::SUCCESS:
                // Check if policy already exists under this idempotency key
                $existing = MockProviderPolicy::where('idempotency_key', $key)->first();
                if ($existing) {
                    return [
                        'status' => 'ISSUED',
                        'policy_number' => $existing->policy_number,
                        'provider_reference' => $existing->provider_reference,
                        'message' => 'Policy recovered from existing idempotency record',
                    ];
                }

                $record = MockProviderPolicy::create([
                    'provider_reference' => $providerRef,
                    'idempotency_key' => $key,
                    'policy_number' => $policyNumber,
                    'customer_name' => $policy->customer_name,
                    'premium_amount' => $policy->premium_amount,
                    'status' => 'ACTIVE',
                ]);

                return [
                    'status' => 'ISSUED',
                    'policy_number' => $record->policy_number,
                    'provider_reference' => $record->provider_reference,
                    'message' => 'Policy issued successfully',
                ];

            case MockScenario::TEMPORARY_FAILURE:
                throw new ProviderTemporaryException(
                    policyId: $policy->id,
                    message: 'Mock insurer returned HTTP 503 Service Unavailable: upstream queue busy'
                );

            case MockScenario::TIMEOUT_AFTER_ISSUE:
                // The mock insurer creates the policy record internally in its ledger...
                $existing = MockProviderPolicy::where('idempotency_key', $key)->first();
                if (! $existing) {
                    $existing = MockProviderPolicy::create([
                        'provider_reference' => $providerRef,
                        'idempotency_key' => $key,
                        'policy_number' => $policyNumber,
                        'customer_name' => $policy->customer_name,
                        'premium_amount' => $policy->premium_amount,
                        'status' => 'ACTIVE',
                    ]);
                }

                // ...but the network/HTTP socket times out before the client receives the response!
                throw new ProviderTimeoutException(
                    policyId: $policy->id,
                    providerReference: $existing->provider_reference,
                    message: "Connection to mock insurer timed out after 5000ms. Policy may have been issued at provider."
                );

            case MockScenario::DELAYED_CALLBACK:
                return [
                    'status' => 'PENDING',
                    'provider_reference' => $providerRef,
                    'message' => 'Policy issuance queued for asynchronous approval. Expect callback with reference.',
                ];

            case MockScenario::DUPLICATE_CALLBACK:
                // Helper scenario that returns ready-to-test duplicate event payloads
                $record = MockProviderPolicy::create([
                    'provider_reference' => $providerRef,
                    'idempotency_key' => $key,
                    'policy_number' => $policyNumber,
                    'customer_name' => $policy->customer_name,
                    'premium_amount' => $policy->premium_amount,
                    'status' => 'ACTIVE',
                ]);

                return [
                    'status' => 'PENDING',
                    'provider_reference' => $record->provider_reference,
                    'sample_event' => [
                        'event_id' => 'EVT-' . strtoupper(Str::random(10)),
                        'event_type' => 'POLICY_ISSUED',
                        'provider_reference' => $record->provider_reference,
                        'policy_number' => $record->policy_number,
                    ],
                ];
        }
    }

    /**
     * Lookup provider policy status for reconciliation.
     */
    public function checkStatus(?string $providerReference, ?string $idempotencyKey = null): array
    {
        $query = MockProviderPolicy::query();

        if ($providerReference) {
            $query->where('provider_reference', $providerReference);
        } elseif ($idempotencyKey) {
            $query->where('idempotency_key', $idempotencyKey);
        } else {
            return ['found' => false, 'status' => 'UNKNOWN'];
        }

        $record = $query->first();

        if ($record) {
            return [
                'found' => true,
                'status' => 'ISSUED',
                'policy_number' => $record->policy_number,
                'provider_reference' => $record->provider_reference,
                'customer_name' => $record->customer_name,
                'premium_amount' => (float) $record->premium_amount,
            ];
        }

        return [
            'found' => false,
            'status' => 'NOT_FOUND',
        ];
    }
}
