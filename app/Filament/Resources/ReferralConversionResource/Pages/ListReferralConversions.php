<?php

namespace App\Filament\Resources\ReferralConversionResource\Pages;

use App\Domain\Referrals\ConversionService;
use App\Enums\Role as RoleEnum;
use App\Filament\Resources\ReferralConversionResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListReferralConversions extends ListRecords
{
    protected static string $resource = ReferralConversionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('runApprovalSweep')
                ->label('Run approval sweep')
                ->icon('heroicon-o-play')
                ->color('primary')
                ->visible(fn () => Auth::user()?->hasRole(RoleEnum::SuperAdmin->value))
                ->requiresConfirmation()
                ->modalDescription('Approve every pending referral conversion whose approval window has elapsed. This action is idempotent.')
                ->action(function (ConversionService $svc): void {
                    $r = $svc->runApprovalSweep(Auth::user());
                    Notification::make()
                        ->title('Approval sweep complete')
                        ->body("Scanned: {$r['scanned']} · Approved: {$r['approved']} · Skipped: {$r['skipped']}")
                        ->success()
                        ->send();
                }),
        ];
    }
}
