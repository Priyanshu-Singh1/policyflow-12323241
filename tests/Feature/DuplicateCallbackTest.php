<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use App\Models\ProviderEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_callbacks_are_idempotently_deduplicated(): void
    {
        // 1. Intake
        $intake = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-ASYNC-001',
            'agent_id' => 'AGENT-99',
            'customer_name' => 'Emma Async',
            'date_of_birth' => '1995-10-10',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-10-01',
            'trip_end' => '2026-10-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);
        $intake->assertStatus(201);
        $policyId = $intake->json('data.id');

        // 2. Submit with DELAYED_CALLBACK
        $submit = $this->postJson("/api/policy-requests/{$policyId}/submit", [
            'scenario' => 'DELAYED_CALLBACK',
        ]);
        $submit->assertStatus(202);
        $providerRef = $submit->json('data.provider_reference');
        $this->assertNotNull($providerRef);

        $policy = PolicyRequest::findOrFail($policyId);
        $this->assertSame('PENDING_CONFIRMATION', $policy->status);

        // 3. First Callback delivery
        $callbackPayload = [
            'event_id' => 'EVT-UNIQUE-999',
            'provider_reference' => $providerRef,
            'event_type' => 'POLICY_ISSUED',
            'policy_number' => 'POL-CONFIRMED-999',
        ];

        $callback1 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $callback1->assertStatus(200)
            ->assertJson([
                'success' => true,
                'duplicate' => false,
                'status' => 'PROCESSED',
            ]);

        $policy->refresh();
        $this->assertSame('ISSUED', $policy->status);
        $this->assertSame('POL-CONFIRMED-999', $policy->policy_number);

        // 4. Repeated Callback delivery (exact same event_id)
        $callback2 = $this->postJson('/api/mock-insurer/callback', $callbackPayload);
        $callback2->assertStatus(200)
            ->assertJson([
                'success' => true,
                'duplicate' => true,
                'status' => 'DUPLICATE_IGNORED',
            ]);

        // State remains ISSUED with the exact same policy number
        $policy->refresh();
        $this->assertSame('ISSUED', $policy->status);
        $this->assertSame('POL-CONFIRMED-999', $policy->policy_number);

        // Assert exactly 1 provider_events record exists
        $this->assertSame(1, ProviderEvent::where('event_id', 'EVT-UNIQUE-999')->count());
    }
}
