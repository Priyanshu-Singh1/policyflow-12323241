<?php

namespace App\Services;

use App\Enums\PolicyStatus;
use App\Exceptions\InvalidStateTransitionException;
use App\Models\PolicyRequest;
use App\Models\PolicyStateHistory;

class PolicyStateMachine
{
    /**
     * Map of legal state transitions.
     * key: source state
     * value: list of permitted destination states
     */
    private const ALLOWED_TRANSITIONS = [
        PolicyStatus::RECEIVED->value => [
            PolicyStatus::VALIDATED->value,
            PolicyStatus::FAILED->value,
        ],
        PolicyStatus::VALIDATED->value => [
            PolicyStatus::SUBMITTED->value,
            PolicyStatus::FAILED->value,
        ],
        PolicyStatus::SUBMITTED->value => [
            PolicyStatus::ISSUED->value,
            PolicyStatus::PENDING_CONFIRMATION->value,
            PolicyStatus::NEEDS_RECONCILIATION->value,
            PolicyStatus::FAILED->value,
        ],
        PolicyStatus::PENDING_CONFIRMATION->value => [
            PolicyStatus::ISSUED->value,
            PolicyStatus::FAILED->value,
            PolicyStatus::NEEDS_RECONCILIATION->value,
        ],
        PolicyStatus::NEEDS_RECONCILIATION->value => [
            PolicyStatus::ISSUED->value,
            PolicyStatus::FAILED->value,
        ],
        PolicyStatus::FAILED->value => [
            PolicyStatus::SUBMITTED->value, // Controlled safe retry using original snapshot
        ],
        PolicyStatus::ISSUED->value => [
            // Strictly terminal: no transitions allowed
        ],
    ];

    /**
     * Check if a transition is legal.
     */
    public function canTransition(string|PolicyStatus $from, string|PolicyStatus $to): bool
    {
        $fromValue = $from instanceof PolicyStatus ? $from->value : $from;
        $toValue = $to instanceof PolicyStatus ? $to->value : $to;

        $allowed = self::ALLOWED_TRANSITIONS[$fromValue] ?? [];
        return in_array($toValue, $allowed, true);
    }

    /**
     * Transition a PolicyRequest model to a new state and record state history.
     * Throws InvalidStateTransitionException if transition is disallowed.
     *
     * @throws InvalidStateTransitionException
     */
    public function transition(
        PolicyRequest $policy,
        string|PolicyStatus $toState,
        string $reason,
        array $metadata = []
    ): PolicyRequest {
        $fromState = $policy->status;
        $targetState = $toState instanceof PolicyStatus ? $toState->value : $toState;

        // If trying to transition to the exact same state (e.g. repeated idempotent trigger)
        if ($fromState === $targetState) {
            return $policy;
        }

        // Strictly check terminal status
        if ($fromState === PolicyStatus::ISSUED->value) {
            throw new InvalidStateTransitionException(
                fromState: $fromState,
                toState: $targetState,
                policyId: $policy->id,
                customReason: "Terminal state 'ISSUED' is immutable and cannot be overwritten or regressed."
            );
        }

        if (! $this->canTransition($fromState, $targetState)) {
            throw new InvalidStateTransitionException(
                fromState: $fromState,
                toState: $targetState,
                policyId: $policy->id,
                customReason: "Transition from '{$fromState}' to '{$targetState}' is not allowed in the state machine."
            );
        }

        // Apply transition
        $policy->status = $targetState;
        $policy->save();

        // Write immutable audit trail entry
        PolicyStateHistory::create([
            'policy_request_id' => $policy->id,
            'from_state' => $fromState,
            'to_state' => $targetState,
            'reason' => $reason,
            'metadata' => $metadata,
            'created_at' => now(),
        ]);

        return $policy;
    }
}
