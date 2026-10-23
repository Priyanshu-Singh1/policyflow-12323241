<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyIntakeTest extends TestCase
{
    use RefreshDatabase;

    private function validPayload(): array
    {
        return [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ];
    }

    public function test_valid_policy_intake_creates_request_with_correct_snapshot(): void
    {
        $response = $this->postJson('/api/policy-requests', $this->validPayload());

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'CREATED',
                'data' => [
                    'client_request_id' => 'AGT-2026-0001',
                    'agent_id' => 'AGENT-17',
                    'customer_name' => 'Sample Customer',
                    'destination' => 'INTERNATIONAL',
                    'plan_code' => 'PLUS',
                    'sum_insured' => 300000,
                    'travel_days' => 5,
                    'premium_amount' => '225.00',
                    'currency' => 'INR',
                    'status' => 'VALIDATED',
                ],
            ]);

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'premium_amount' => 225.00,
            'status' => 'VALIDATED',
        ]);
    }

    public function test_missing_required_fields_returns_422(): void
    {
        $response = $this->postJson('/api/policy-requests', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['error', 'message', 'errors'])
            ->assertJsonValidationErrors([
                'client_request_id',
                'agent_id',
                'customer_name',
                'date_of_birth',
                'destination',
                'trip_start',
                'trip_end',
                'plan_code',
                'sum_insured',
            ]);
    }

    public function test_invalid_travel_dates_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['trip_start'] = '2026-11-14';
        $payload['trip_end'] = '2026-11-10'; // trip_end < trip_start

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['trip_end']);
    }

    public function test_unsupported_plan_code_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['plan_code'] = 'SUPER_PLATINUM';

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan_code']);
    }

    public function test_unsupported_sum_insured_rejected(): void
    {
        $payload = $this->validPayload();
        $payload['sum_insured'] = 999999;

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['sum_insured']);
    }
}
