<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PackageResource\Pages;
use App\Models\Package;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PackageResource extends Resource
{
    protected static ?string $model = Package::class;

    protected static ?string $slug = 'packages';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Packages';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Basics')->schema([
                TextInput::make('name')->required()->maxLength(190)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set, string $operation) =>
                        $operation === 'create' ? $set('slug', Str::slug((string) $state)) : null
                    ),
                TextInput::make('slug')->required()->maxLength(190)->unique(ignoreRecord: true)
                    ->helperText('Used in the checkout URL. Lowercase, hyphenated.'),
                TextInput::make('short_description')->required()->maxLength(500),
                Textarea::make('full_description')->rows(3)->maxLength(2000),
            ])->columns(2),

            Section::make('Pricing')->schema([
                TextInput::make('amount_minor')->numeric()->required()->minValue(0)
                    ->label('Amount (minor units, pence)')
                    ->helperText('£60.00 → 6000. Stripe uses the smallest currency unit.'),
                TextInput::make('currency')->required()->maxLength(3)->default('gbp')
                    ->helperText('ISO 4217 lowercase (gbp/usd/eur).'),
                TextInput::make('duration_label')->required()->maxLength(64)
                    ->placeholder('12 months'),
                TextInput::make('stripe_price_id')->maxLength(191)
                    ->label('Stripe price ID (optional)')
                    ->helperText('If set, Stripe uses this exact price. Otherwise price_data is sent inline.'),
            ])->columns(2),

            Section::make('Flags')->schema([
                Toggle::make('includes_vpn'),
                Toggle::make('is_active')->default(true),
                TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('sort_order')->label('#')->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('slug')->searchable()->toggleable(),
                TextColumn::make('duration_label')->label('Duration'),
                TextColumn::make('amount_minor')->label('Price')
                    ->formatStateUsing(fn (Package $r) => $r->priceFormatted())
                    ->sortable(),
                IconColumn::make('includes_vpn')->label('VPN')->boolean(),
                IconColumn::make('is_active')->label('Active')->boolean(),
                TextColumn::make('stripe_price_id')->label('Stripe price')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active'),
                TernaryFilter::make('includes_vpn')->label('Includes VPN'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->headerActions([CreateAction::make()]);
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPackages::route('/'),
            'create' => Pages\CreatePackage::route('/create'),
            'edit' => Pages\EditPackage::route('/{record}/edit'),
        ];
    }
}
