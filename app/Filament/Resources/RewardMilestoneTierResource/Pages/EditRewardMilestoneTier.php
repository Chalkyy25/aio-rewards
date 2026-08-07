<?php

namespace App\Filament\Resources\RewardMilestoneTierResource\Pages;

use App\Filament\Resources\RewardMilestoneTierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRewardMilestoneTier extends EditRecord
{
    protected static string $resource = RewardMilestoneTierResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
