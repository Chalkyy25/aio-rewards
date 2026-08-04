<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $stripe_event_id
 * @property string $type
 * @property bool $livemode
 * @property array $payload
 * @property bool $signature_verified
 * @property ?\Illuminate\Support\Carbon $processed_at
 * @property ?string $processing_error
 */
class StripeEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'stripe_event_id', 'type', 'livemode', 'payload',
        'signature_verified', 'processed_at', 'processing_error',
    ];

    protected function casts(): array
    {
        return [
            'livemode' => 'boolean',
            'signature_verified' => 'boolean',
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
