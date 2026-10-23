<?php

namespace App\Models;

use App\Enums\PolicyStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'id',
        'client_request_id',
        'agent_id',
        'customer_name',
        'date_of_birth',
        'destination',
        'trip_start',
        'trip_end',
        'plan_code',
        'sum_insured',
        'travel_days',
        'premium_amount',
        'currency',
        'status',
        'policy_number',
        'provider_reference',
        'attempt_count',
        'last_response',
    ];

    protected $casts = [
        'date_of_birth' => 'date:Y-m-d',
        'trip_start' => 'date:Y-m-d',
        'trip_end' => 'date:Y-m-d',
        'sum_insured' => 'integer',
        'travel_days' => 'integer',
        'premium_amount' => 'decimal:2',
        'attempt_count' => 'integer',
        'last_response' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function attempts(): HasMany
    {
        return $this->hasMany(IssuanceAttempt::class, 'policy_request_id')->orderBy('attempt_number', 'asc');
    }

    public function stateHistories(): HasMany
    {
        return $this->hasMany(PolicyStateHistory::class, 'policy_request_id')->orderBy('created_at', 'asc');
    }

    public function events(): HasMany
    {
        return $this->hasMany(ProviderEvent::class, 'policy_request_id')->orderBy('created_at', 'asc');
    }

    public function isIssued(): bool
    {
        return $this->status === PolicyStatus::ISSUED->value;
    }
}
