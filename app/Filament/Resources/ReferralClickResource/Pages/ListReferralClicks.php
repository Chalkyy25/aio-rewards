<?php

namespace App\Filament\Resources\ReferralClickResource\Pages;

use App\Filament\Resources\ReferralClickResource;
use Filament\Resources\Pages\ListRecords;
use Filament\Widgets\Widget;

class ListReferralClicks extends ListRecords
{
    protected static string $resource = ReferralClickResource::class;

    /** @return array<int, class-string<Widget>> */
    protected function getHeaderWidgets(): array
    {
        return ReferralClickResource::getWidgets();
    }
}
