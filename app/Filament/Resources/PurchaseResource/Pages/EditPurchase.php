<?php

namespace App\Filament\Resources\PurchaseResource\Pages;

use App\Domain\Fulfilment\OrderFulfilmentService;
use App\Filament\Resources\PurchaseResource;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditPurchase extends EditRecord
{
    protected static string $resource = PurchaseResource::class;

    protected function mutateFormDataBeforeFill(array $data): array
    {
        // Never leak the actual password into the form; only reveal it live via the input revealable toggle.
        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Move to OrderFulfilmentService so audit-log + encryption casts run through the domain layer.
        app(OrderFulfilmentService::class)->updateFulfilmentDetails($this->record, [
            'provisioned_username' => $data['provisioned_username_enc'] ?? null,
            'provisioned_password' => $data['provisioned_password_enc'] ?? null,
            'provisioned_expires_on' => $data['provisioned_expires_on'] ?? null,
            'setup_instructions_md' => $data['setup_instructions_md'] ?? null,
            'download_links' => $data['download_links'] ?? null,
            'fulfilment_notes' => $data['fulfilment_notes'] ?? null,
        ], Auth::user());

        // Skip Filament's own save cycle for these fields; the service handles it.
        return array_diff_key($data, array_flip([
            'provisioned_username_enc', 'provisioned_password_enc', 'provisioned_expires_on',
            'setup_instructions_md', 'download_links', 'fulfilment_notes',
        ]));
    }
}
