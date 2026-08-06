<?php

namespace App\Domain\Ambassadors\Exceptions;

use App\Domain\Provider\Enums\VerificationFailureReason;
use RuntimeException;

class ActivationException extends RuntimeException
{
    public function __construct(
        public readonly string $reasonKey,
        public readonly string $publicMessage,
    ) {
        parent::__construct($publicMessage);
    }

    public static function providerRejected(VerificationFailureReason $reason): self
    {
        return new self('provider_rejected', $reason->operatorMessage());
    }

    public static function usernameAlreadyActivated(): self
    {
        return new self(
            'username_already_activated',
            'This provider username has already been used to activate an AIO Rewards account.',
        );
    }

    public static function emailAlreadyRegistered(): self
    {
        return new self(
            'email_already_registered',
            'That email address is already registered. Please sign in or reset your password.',
        );
    }

    public static function providerUnavailable(): self
    {
        return new self(
            'provider_unavailable',
            "We’re temporarily unable to verify your AIO Media account. Please try again later or contact support.",
        );
    }
}
