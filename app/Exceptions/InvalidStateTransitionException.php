<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InvalidStateTransitionException extends Exception
{
    public function __construct(
        public readonly string $fromState,
        public readonly string $toState,
        public readonly ?string $policyId = null,
        string $customReason = 'State transition is not permitted'
    ) {
        $message = "Cannot transition policy [{$policyId}] from '{$fromState}' to '{$toState}': {$customReason}";
        parent::__construct($message, 409);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'INVALID_STATE_TRANSITION',
            'message' => $this->getMessage(),
            'policy_id' => $this->policyId,
            'from_state' => $this->fromState,
            'to_state' => $this->toState,
        ], 409);
    }
}
