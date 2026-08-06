<?php

namespace App\Enums;

enum OperationsPriority: string
{
    case Critical = 'critical';
    case High = 'high';
    case Medium = 'medium';
    case Low = 'low';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Critical => 'danger',
            self::High => 'warning',
            self::Medium => 'primary',
            self::Low => 'gray',
        };
    }

    public function rank(): int
    {
        return match ($this) {
            self::Critical => 4,
            self::High => 3,
            self::Medium => 2,
            self::Low => 1,
        };
    }

    /** Escalate this priority up one level (Low→Medium→High→Critical→Critical). */
    public function escalate(): self
    {
        return match ($this) {
            self::Low => self::Medium,
            self::Medium => self::High,
            self::High => self::Critical,
            self::Critical => self::Critical,
        };
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        return [
            self::Critical->value => self::Critical->label(),
            self::High->value => self::High->label(),
            self::Medium->value => self::Medium->label(),
            self::Low->value => self::Low->label(),
        ];
    }
}
