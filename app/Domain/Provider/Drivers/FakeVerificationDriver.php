<?php

namespace App\Domain\Provider\Drivers;

use App\Domain\Provider\Contracts\CustomerVerificationContract;
use App\Domain\Provider\DTOs\VerifyCustomerRequest;
use App\Domain\Provider\DTOs\VerifyCustomerResult;
use App\Domain\Provider\Enums\VerificationFailureReason;

/**
 * Development / test verification driver.
 *
 * Rules (case-insensitive username match):
 *   - "test_active"    + password "letmein" → eligible (legacy alias)
 *   - "test_active_1"  + password "letmein" → eligible
 *   - "test_active_2"  + password "letmein" → eligible
 *   - "test_active_3"  + password "letmein" → eligible
 *   - "test_inactive"  + password "letmein" → inactive
 *   - "test_error"     (any password)       → error (simulated outage)
 *   - Any other username → not_found
 *   - Correct username but wrong password → wrong_credentials
 *
 * Each active username is activatable only ONCE — AmbassadorProfile enforces
 * a unique provider_username, so repeat activations of the same username are
 * blocked at the DB / service layer, not by the fake driver.
 *
 * Additional runtime rules can be provided via constructor for targeted tests.
 */
class FakeVerificationDriver implements CustomerVerificationContract
{
    /**
     * @param array<string, array{password: string, result: string}> $rules
     *                                                                      Keyed by lower-cased username. `result` is one of:
     *                                                                      'eligible', 'inactive', 'ineligible', 'not_found', 'wrong_credentials', 'error'.
     */
    public function __construct(private array $rules = [])
    {
        $this->rules = array_change_key_case(array_merge([
            // Legacy alias — the first "test_active" account, kept so existing
            // documentation continues to work.
            'test_active' => ['password' => 'letmein', 'result' => 'eligible'],
            // Reusable browser-testing accounts. Each can be activated ONCE
            // because AmbassadorProfile enforces a unique provider_username.
            'test_active_1' => ['password' => 'letmein', 'result' => 'eligible'],
            'test_active_2' => ['password' => 'letmein', 'result' => 'eligible'],
            'test_active_3' => ['password' => 'letmein', 'result' => 'eligible'],
            'test_inactive' => ['password' => 'letmein', 'result' => 'inactive'],
            'test_error' => ['password' => '__any__', 'result' => 'error'],
        ], $rules), CASE_LOWER);
    }

    public function verifyActiveCustomer(VerifyCustomerRequest $request): VerifyCustomerResult
    {
        $key = strtolower($request->providerUsername);
        $rule = $this->rules[$key] ?? null;

        if ($rule === null) {
            return VerifyCustomerResult::reject(VerificationFailureReason::NotFound);
        }

        if ($rule['result'] === 'error') {
            return VerifyCustomerResult::reject(VerificationFailureReason::Error);
        }

        if ($rule['password'] !== '__any__' && ! hash_equals($rule['password'], $request->providerPassword)) {
            return VerifyCustomerResult::reject(VerificationFailureReason::WrongCredentials);
        }

        return match ($rule['result']) {
            'eligible' => VerifyCustomerResult::eligible('fake-ref-'.$key),
            'inactive' => VerifyCustomerResult::reject(VerificationFailureReason::Inactive),
            'ineligible' => VerifyCustomerResult::reject(VerificationFailureReason::Ineligible),
            'not_found' => VerifyCustomerResult::reject(VerificationFailureReason::NotFound),
            'wrong_credentials' => VerifyCustomerResult::reject(VerificationFailureReason::WrongCredentials),
            default => VerifyCustomerResult::reject(VerificationFailureReason::Error),
        };
    }

    public function driverKey(): string
    {
        return 'fake';
    }
}
