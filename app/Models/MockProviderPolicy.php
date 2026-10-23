<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MockProviderPolicy extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider_reference',
        'idempotency_key',
        'policy_number',
        'customer_name',
        'premium_amount',
        'status',
    ];

    protected $casts = [
        'premium_amount' => 'decimal:2',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
