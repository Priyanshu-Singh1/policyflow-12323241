<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TemporaryFailureRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_temporary_failure_allows_safe_retry_with_original_premium_snapshot(): void
    {
        // 1. Intake
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-RETRY-001',
            'agent_id' => 'AGENT-77',
            'customer_name' => 'Frank Retry',
            'date_of_birth' => '1980-05-10',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-08-01',
            'trip_end' => '2026-08-10',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);
        $intake->assertStatus(201);
        $policyId = $intake->json('data.id');
        $originalPremium = $intake->json('data.premium_amount');

        // 2. Submit with TEMPORARY_FAILURE scenario
        $submit1 = $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'TEMPORARY_FAILURE',
        ]);

        $submit1->assertStatus(503)
            ->assertJson([
                'error' => 'PROVIDER_TEMPORARY_FAILURE',
                'status' => 'FAILED',
                'retryable' => true,
            ]);

        $policy = PolicyRequest::findOrFail($policyId);
        $this->assertSame('FAILED', $policy->status);
        $this->assertSame(1, $policy->attempt_count);

        // 3. Retry with SUCCESS scenario
        $submit2 = $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'SUCCESS',
        ]);

        $submit2->assertStatus(200)
            ->assertJson([
                'status' => 'ISSUED',
                'data' => [
                    'id' => $policyId,
                    'status' => 'ISSUED',
                    'attempt_count' => 2,
                    'premium_amount' => $originalPremium, // Immutable snapshot preserved
                ],
            ]);

        $policy->refresh();
        $this->assertSame('ISSUED', $policy->status);
        $this->assertNotNull($policy->policy_number);
        $this->assertCount(2, $policy->attempts);
        $this->assertSame('FAILED', $policy->attempts[0]->status);
        $this->assertSame('SUCCESS', $policy->attempts[1]->status);
    }
}
