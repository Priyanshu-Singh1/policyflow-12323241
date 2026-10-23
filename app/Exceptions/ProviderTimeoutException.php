<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProviderTimeoutException extends Exception
{
    public function __construct(
        public readonly ?string $policyId = null,
        public readonly ?string $providerReference = null,
        string $message = 'Provider connection timed out after issuance attempt. State requires reconciliation.'
    ) {
        parent::__construct($message, 504);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'PROVIDER_TIMEOUT',
            'message' => $this->getMessage(),
            'policy_id' => $this->policyId,
            'provider_reference' => $this->providerReference,
            'status' => 'NEEDS_RECONCILIATION',
            'reconciliation_endpoint' => $this->policyId ? "/api/policy-requests/{$this->policyId}/reconcile" : null,
        ], 504);
    }
}
