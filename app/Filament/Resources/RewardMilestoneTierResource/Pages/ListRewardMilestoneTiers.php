<?php

namespace App\Filament\Resources\RewardMilestoneTierResource\Pages;

use App\Filament\Resources\RewardMilestoneTierResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRewardMilestoneTiers extends ListRecords
{
    protected static string $resource = RewardMilestoneTierResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
