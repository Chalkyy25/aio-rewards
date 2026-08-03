<?php

namespace App\Domain\Provider\DTOs;

use SensitiveParameter;

/**
 * Value object carrying the credentials needed for a provider verification
 * call. The `providerPassword` is marked as a sensitive parameter so it is
 * scrubbed from stack traces.
 *
 * This DTO is passed *only* to the CustomerVerificationContract implementation.
 * It must never be persisted, serialised into a queue, logged, cached, or
 * included in an exception body / audit log.
 */
final class VerifyCustomerRequest
{
    public function __construct(
        public readonly string $providerUsername,
        #[SensitiveParameter]
        public readonly string $providerPassword,
        public readonly ?string $email = null,
    ) {}
}
