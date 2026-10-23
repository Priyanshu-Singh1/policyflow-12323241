<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationsSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_summary_reports_accurate_counts_and_metrics(): void
    {
        // 1. Seed requests in various states
        // ISSUED
        PolicyRequest::create([
            'client_request_id' => 'OP-01',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'User One',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium_amount' => 120.00,
            'currency' => 'INR',
            'status' => 'ISSUED',
            'policy_number' => 'POL-OP-01',
        ]);

        // NEEDS_RECONCILIATION
        PolicyRequest::create([
            'client_request_id' => 'OP-02',
            'agent_id' => 'AGENT-01',
            'customer_name' => 'User Two',
            'date_of_birth' => '1990-01-01',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
            'travel_days' => 5,
            'premium_amount' => 225.00,
            'currency' => 'INR',
            'status' => 'NEEDS_RECONCILIATION',
            'provider_reference' => 'REF-OP-02',
        ]);

        // FAILED
        PolicyRequest::create([
            'client_request_id' => 'OP-03',
            'agent_id' => 'AGENT-02',
            'customer_name' => 'User Three',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-06-01',
            'trip_end' => '2026-06-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'travel_days' => 5,
            'premium_amount' => 60.00,
            'currency' => 'INR',
            'status' => 'FAILED',
        ]);

        $response = $this->getJson('/api/operations/summary');

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'SUCCESS',
                'summary' => [
                    'total_requests' => 3,
                    'issued_policies' => 1,
                    'pending_confirmations' => 0,
                    'needs_reconciliation' => 1,
                    'failed_requests' => 1,
                    'total_premium_issued_inr' => 120.00,
                ],
            ]);

        $this->assertCount(1, $response->json('needs_reconciliation_items'));
        $this->assertSame('OP-02', $response->json('needs_reconciliation_items.0.client_request_id'));
    }
}
