<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssuanceWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_issuance_happy_path_produces_single_policy_number_and_audit_trail(): void
    {
        // 1. Intake
        $intakeResponse = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-FLOW-001',
            'agent_id' => 'AGENT-42',
            'customer_name' => 'Charlie Flow',
            'date_of_birth' => '1992-03-15',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-07-01',
            'trip_end' => '2026-07-10',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
        ]);

        $intakeResponse->assertStatus(201);
        $policyId = $intakeResponse->json('data.id');

        // 2. Submit with SUCCESS scenario
        $submitResponse = $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $submitResponse->assertStatus(200)
            ->assertJson([
                'status' => 'ISSUED',
                'data' => [
                    'id' => $policyId,
                    'status' => 'ISSUED',
                    'attempt_count' => 1,
                ],
            ]);

        $policyNumber = $submitResponse->json('data.policy_number');
        $this->assertNotNull($policyNumber);
        $this->assertStringStartsWith('POL-', $policyNumber);

        // 3. Inspect audit trail via GET
        $getResponse = $this->getJson("/api/policy-requests/{$policyId}");
        $getResponse->assertStatus(200)
            ->assertJsonStructure([
                'status',
                'data' => ['id', 'status', 'policy_number', 'provider_reference', 'premium_amount'],
                'audit_history',
                'issuance_attempts',
            ]);

        $history = $getResponse->json('audit_history');
        // Transitions: RECEIVED -> VALIDATED, VALIDATED -> SUBMITTED, SUBMITTED -> ISSUED
        $this->assertGreaterThanOrEqual(2, count($history));

        $attempts = $getResponse->json('issuance_attempts');
        $this->assertCount(1, $attempts);
        $this->assertSame('SUCCESS', $attempts[0]['status']);
    }
}
