<?php

namespace App\Domain\Provider\Enums;

enum VerificationFailureReason: string
{
    case NotFound = 'not_found';
    case WrongCredentials = 'wrong_credentials';
    case Inactive = 'inactive';
    case Ineligible = 'ineligible';
    case Error = 'error';

    public function operatorMessage(): string
    {
        return match ($this) {
            self::NotFound => 'We could not verify your subscription. Please check your username and password.',
            self::WrongCredentials => 'We could not verify your subscription. Please check your username and password.',
            self::Inactive => 'Your subscription is not currently active. Please contact support.',
            self::Ineligible => 'Your subscription is not eligible for the AIO Rewards programme.',
            self::Error => 'We are temporarily unable to verify your subscription. Please try again shortly.',
        };
    }
}
