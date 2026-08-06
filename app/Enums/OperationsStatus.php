<?php

namespace App\Enums;

enum OperationsStatus: string
{
    case New = 'new';
    case Seen = 'seen';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Seen => 'Seen',
            self::Assigned => 'Assigned',
            self::InProgress => 'In progress',
            self::Resolved => 'Resolved',
            self::Dismissed => 'Dismissed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::New => 'danger',
            self::Seen => 'warning',
            self::Assigned => 'info',
            self::InProgress => 'primary',
            self::Resolved => 'success',
            self::Dismissed => 'gray',
        };
    }

    public function isOpen(): bool
    {
        return ! in_array($this, [self::Resolved, self::Dismissed], true);
    }

    /** Open statuses considered "actionable" for dedupe / scan. */
    public static function openValues(): array
    {
        return [self::New->value, self::Seen->value, self::Assigned->value, self::InProgress->value];
    }

    /** @return array<string,string> */
    public static function options(): array
    {
        $out = [];
        foreach (self::cases() as $c) {
            $out[$c->value] = $c->label();
        }

        return $out;
    }
}
