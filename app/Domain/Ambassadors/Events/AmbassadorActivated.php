<?php

namespace App\Domain\Ambassadors\Events;

use App\Models\AmbassadorProfile;
use Illuminate\Foundation\Events\Dispatchable;

class AmbassadorActivated
{
    use Dispatchable;

    public function __construct(public readonly AmbassadorProfile $ambassador) {}
}
