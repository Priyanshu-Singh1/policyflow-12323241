<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DuplicateIntakeTest extends TestCase
{
    use RefreshDatabase;

    private function basePayload(): array
    {
        return [
            'client_request_id' => 'AGT-2026-DUP01',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'John Doe',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-12-01',
            'trip_end' => '2026-12-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];
    }

    /**
     * Case A: Repeated intake requests return existing entity with HTTP 200 without creating duplicates.
     */
    public function test_repeated_intake_returns_existing_record_with_200_ok(): void
    {
        // 1st request -> 201 Created
        $response1 = $this->postJson('/api/policy-requests', $this->basePayload());
        $response1->assertStatus(201);
        $policyId1 = $response1->json('data.id');

        // 2nd request -> 200 OK (Safely repeated)
        $response2 = $this->postJson('/api/policy-requests', $this->basePayload());
        $response2->assertStatus(200)
            ->assertJson([
                'status' => 'EXISTING_REQUEST',
                'data' => [
                    'id' => $policyId1,
                    'client_request_id' => 'AGT-2026-DUP01',
                    'agent_id' => 'AGENT-17',
                ],
            ]);

        // Assert exactly 1 record exists in the database
        $this->assertSame(1, PolicyRequest::where('agent_id', 'AGENT-17')
            ->where('client_request_id', 'AGT-2026-DUP01')
            ->count());
    }

    /**
     * Case A: Repeated intake preserves immutable pricing snapshot even if caller attempts to send different values.
     */
    public function test_repeated_intake_preserves_immutable_pricing_snapshot(): void
    {
        // 1st request with BASIC plan (premium = 12 * 5 = 60.00)
        $response1 = $this->postJson('/api/policy-requests', $this->basePayload());
        $response1->assertStatus(201);
        $originalPremium = $response1->json('data.premium_amount');
        $this->assertEquals('60.00', $originalPremium);

        // 2nd request with same agent_id + client_request_id, but tampering with plan_code = 'PREMIUM'
        $tamperedPayload = $this->basePayload();
        $tamperedPayload['plan_code'] = 'PREMIUM';

        $response2 = $this->postJson('/api/policy-requests', $tamperedPayload);
        $response2->assertStatus(200);

        // Assert the returned premium is STILL the original accepted snapshot of 60.00
        $this->assertEquals('60.00', $response2->json('data.premium_amount'));
        $this->assertEquals('BASIC', $response2->json('data.plan_code'));
    }

    /**
     * Case A: 20 repeated intake calls produce exactly 1 database record.
     */
    public function test_twenty_repeated_intakes_produce_single_database_record(): void
    {
        $payload = $this->basePayload();

        for ($i = 0; $i < 20; $i++) {
            $response = $this->postJson('/api/policy-requests', $payload);
            if ($i === 0) {
                $response->assertStatus(201);
            } else {
                $response->assertStatus(200);
            }
        }

        $this->assertSame(1, PolicyRequest::where('agent_id', 'AGENT-17')
            ->where('client_request_id', 'AGT-2026-DUP01')
            ->count());
    }
}
