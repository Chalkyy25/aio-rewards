<?php

namespace App\Domain\Ambassadors\DTOs;

use SensitiveParameter;

/**
 * Immutable input to AmbassadorActivationService::activate().
 * The provider password is sensitive and must not be stored, logged,
 * cached, queued, or included in exceptions/audit rows.
 */
final class ActivationInput
{
    public function __construct(
        public readonly string $providerUsername,
        #[SensitiveParameter]
        public readonly string $providerPassword,
        public readonly string $email,
        public readonly string $name,
        #[SensitiveParameter]
        public readonly string $newPassword,
    ) {}
}
