<?php

namespace App\Filament\Resources\RewardResource\Pages;

use App\Filament\Actions\MarkRewardPaidActionFactory;
use App\Filament\Actions\RevealPayoutDetailsActionFactory;
use App\Filament\Resources\RewardResource;
use App\Models\Reward;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewReward extends ViewRecord
{
    protected static string $resource = RewardResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            RevealPayoutDetailsActionFactory::reveal(
                resolveProfile: function () {
                    /** @var Reward $record */
                    $record = $this->getRecord();

                    return $record->ambassadorProfile?->payoutProfile;
                },
                source: 'reward_admin',
                resolveReward: fn () => $this->getRecord(),
                additionalVisible: function (): bool {
                    /** @var Reward $record */
                    $record = $this->getRecord();

                    // Only while the reward is approved / awaiting payment.
                    return $record->status === 'approved';
                },
            ),
            MarkRewardPaidActionFactory::make(),
            RevealPayoutDetailsActionFactory::showModal(),
        ];
    }
}
