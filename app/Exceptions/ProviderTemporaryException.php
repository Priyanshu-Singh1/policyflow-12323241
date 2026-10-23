<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderTemporaryException extends Exception
{
    public function __construct(
        public readonly ?string $policyId = null,
        string $message = 'Provider returned a temporary failure (503 Service Unavailable). Safe retry is allowed.'
    ) {
        parent::__construct($message, 503);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'PROVIDER_TEMPORARY_FAILURE',
            'message' => $this->getMessage(),
            'policy_id' => $this->policyId,
            'status' => 'FAILED',
            'retryable' => true,
        ], 503);
    }
}
