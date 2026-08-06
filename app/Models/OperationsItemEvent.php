<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $operations_item_id
 * @property ?int $actor_user_id
 * @property string $action
 * @property ?array $payload
 * @property \Illuminate\Support\Carbon $created_at
 */
class OperationsItemEvent extends Model
{
    protected $table = 'operations_item_events';

    public $timestamps = false;

    protected $fillable = ['operations_item_id', 'actor_user_id', 'action', 'payload', 'created_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OperationsItem::class, 'operations_item_id');
    }
}
