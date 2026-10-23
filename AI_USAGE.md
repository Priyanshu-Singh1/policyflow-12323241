# AI Usage Disclosure

In compliance with Section 10 of the Practical Assessment specification, this document discloses how artificial intelligence tools were utilized during the development of PolicyFlow.

---

### 1. Tools Used
- **Antigravity (Claude 3.7 Sonnet / Gemini coding assistant)**: Architecture planning, rapid scaffolding of Laravel migrations and models, and formulation of edge-case test suites.

---

### 2. Concrete Ways AI Assisted
1. **Mathematical Pricing & Boundary Edge Cases**:
   Generated the deterministic formula test matrix for `PremiumCalculator`, verifying boundary ages (0, 17, 18, 60, 61, 70, 71+), inclusive same-day trips (`trip_end == trip_start`), and validating exact match against the specification reference calculation (`₹225.00` for a 28-year-old on PLUS plan, 5 days international, ₹300,000 sum insured).
2. **State Machine Matrix & Idempotency Strategy**:
   Assisted in structuring the explicit state transition graph, ensuring `ISSUED` remained strictly terminal (throwing `InvalidStateTransitionException` with HTTP 409 Conflict) and implementing database-level composite uniqueness on `(agent_id, client_request_id)`.
3. **Failure-Injection Test Suite**:
   Assisted in synthesizing deterministic test cases for unreliable insurer scenarios: timeout handling with reconciliation, duplicate webhook callbacks, and temporary failure retries with immutable premium snapshots.

---

### 3. Incorrect or Incomplete Suggestion Encountered & Correction
- **Issue**: During the initial implementation of `IssuanceService::submit`, the AI proposed wrapping the entire method—including the external mock insurer invocation and exception handling—inside a single Laravel `DB::transaction(...)`.
- **Why it was defective**: When `ProviderTimeoutException` or `ProviderTemporaryException` was caught, the method updated the policy status to `NEEDS_RECONCILIATION` or `FAILED`, created an `IssuanceAttempt` record, and re-threw the exception so the HTTP controller could return 504 Gateway Timeout or 503 Service Unavailable. However, because the exception was re-thrown within the `DB::transaction()` closure, Laravel automatically executed a database rollback, inadvertently reverting the status back to `VALIDATED` and wiping the recorded failure attempt.
- **Resolution**: Identified through automated testing (`TemporaryFailureRetryTest` and `TimeoutReconciliationTest`). Refactored `submit()` using enterprise best practices:
  1. The initial transition to `SUBMITTED` is committed in an initial fast transaction with row-level locks.
  2. The external provider call is made *outside* open database transactions (preventing long-running network I/O from holding database locks).
  3. The outcome (whether success, timeout, or temporary failure) is persisted and committed in its own dedicated transaction before rendering or re-throwing the HTTP exception.

---

### 4. Verification and Validation Methodology
- **Automated Test Execution**: All functionality was verified by running `php artisan test`, ensuring 100% pass rate across 29 unit and feature tests with 167 assertions.
- **Contract Adherence**: Tested all HTTP status codes (201, 200, 422, 404, 409, 503, 504) against published requirements.
- **Database Layer Verification**: Checked that composite uniqueness on `(agent_id, client_request_id)` and unique constraints on `event_id` and `policy_number` prevent duplicate records at the database engine level, not merely in application logic.
