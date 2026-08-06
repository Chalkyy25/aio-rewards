<?php

namespace App\Domain\Operations;

use App\Enums\OperationsPriority;
use App\Enums\OperationsType;
use Illuminate\Database\Eloquent\Model;

/**
 * Immutable description of a work item the scanner wants to create/keep.
 * `OperationsWriter::upsert()` reads it and applies dedupe rules.
 */
final class OperationsSpec
{
    public function __construct(
        public readonly OperationsType $type,
        public readonly string $dedupeKey,
        public readonly string $title,
        public readonly ?string $summary = null,
        public readonly ?OperationsPriority $priority = null,
        public readonly ?Model $subject = null,
        public readonly ?\DateTimeInterface $dueAt = null,
        /** @var array<string,mixed> */
        public readonly array $meta = [],
    ) {}
}
