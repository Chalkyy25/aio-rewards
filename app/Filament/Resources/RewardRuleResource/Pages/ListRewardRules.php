<?php

namespace App\Filament\Resources\RewardRuleResource\Pages;

use App\Filament\Resources\RewardRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListRewardRules extends ListRecords
{
    protected static string $resource = RewardRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
