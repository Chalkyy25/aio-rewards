<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string $short_description
 * @property ?string $full_description
 * @property ?string $stripe_price_id
 * @property int $amount_minor
 * @property string $currency
 * @property string $duration_label
 * @property bool $includes_vpn
 * @property bool $is_active
 * @property int $sort_order
 */
class Package extends Model
{
    /** @use HasFactory<\Database\Factories\PackageFactory> */
    use HasFactory;

    protected $fillable = [
        'name', 'slug', 'short_description', 'full_description',
        'stripe_price_id', 'amount_minor', 'currency', 'duration_label',
        'includes_vpn', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'includes_vpn' => 'boolean',
            'is_active' => 'boolean',
            'amount_minor' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function priceFormatted(): string
    {
        return match (strtolower($this->currency)) {
            'gbp' => '£'.number_format($this->amount_minor / 100, 2),
            'eur' => '€'.number_format($this->amount_minor / 100, 2),
            'usd' => '$'.number_format($this->amount_minor / 100, 2),
            default => strtoupper($this->currency).' '.number_format($this->amount_minor / 100, 2),
        };
    }
}
