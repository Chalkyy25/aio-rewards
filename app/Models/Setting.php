<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Simple key/value store for admin-editable branding, public-page copy
 * and customer message templates. Access via the SettingsRepository —
 * DO NOT read this model directly (the repository caches values).
 *
 * @property string $key
 * @property ?string $value
 */
class Setting extends Model
{
    protected $fillable = ['key', 'value', 'updated_by_user_id'];
}
