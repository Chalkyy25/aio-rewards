<?php

namespace App\Filament\Resources\AmbassadorResource\Pages;

use App\Filament\Actions\RevealPayoutDetailsActionFactory;
use App\Filament\Resources\AmbassadorResource;
use App\Models\AmbassadorProfile;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewAmbassador extends ViewRecord
{
    protected static string $resource = AmbassadorResource::class;

    /**
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            RevealPayoutDetailsActionFactory::reveal(
                resolveProfile: function () {
                    /** @var AmbassadorProfile $record */
                    $record = $this->getRecord();

                    return $record->payoutProfile;
                },
                source: 'ambassador_admin',
            ),
            RevealPayoutDetailsActionFactory::showModal(),
        ];
    }
}
