<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReferralClickResource\Pages;
use App\Filament\Resources\ReferralClickResource\Widgets\ReferralClickStats;
use App\Models\ReferralClick;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Builder;

class ReferralClickResource extends Resource
{
    protected static ?string $model = ReferralClick::class;

    protected static ?string $slug = 'referral-clicks';

    protected static string|null|\BackedEnum $navigationIcon = 'heroicon-o-cursor-arrow-rays';

    protected static ?string $navigationLabel = 'Referral clicks';

    protected static ?string $recordTitleAttribute = 'attribution_id';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Attribution')->schema([
                TextEntry::make('attribution_id')->copyable()->label('Attribution ID'),
                TextEntry::make('referral_code_snapshot')->badge(),
                TextEntry::make('ambassador.user.name')->label('Ambassador'),
                TextEntry::make('ambassador.user.email')->label('Ambassador email'),
                TextEntry::make('created_at')->dateTime(),
            ])->columns(2),

            Section::make('Signal')->schema([
                TextEntry::make('is_bot')->badge()
                    ->color(fn ($state) => $state ? 'warning' : 'success')
                    ->formatStateUsing(fn ($state) => $state ? 'Bot' : 'Valid'),
                TextEntry::make('user_agent')->label('User agent'),
                TextEntry::make('referer_url')->label('Referer'),
            ])->columns(1),

            Section::make('UTM')->schema([
                TextEntry::make('utm_source')->placeholder('—'),
                TextEntry::make('utm_medium')->placeholder('—'),
                TextEntry::make('utm_campaign')->placeholder('—'),
            ])->columns(3),

            Section::make('Privacy')->schema([
                TextEntry::make('ip_hash')->label('IP hash (SHA-256 HMAC of raw IP; raw IP never stored)')
                    ->copyable(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('When')->dateTime()->sortable(),
                TextColumn::make('ambassador.user.name')->label('Ambassador')->searchable(),
                TextColumn::make('referral_code_snapshot')->label('Code')->badge()->searchable(),
                IconColumn::make('is_bot')->label('Bot')->boolean(),
                TextColumn::make('utm_source')->label('UTM src')->toggleable()->placeholder('—'),
                TextColumn::make('utm_medium')->label('UTM med')->toggleable()->placeholder('—'),
                TextColumn::make('utm_campaign')->label('UTM camp')->toggleable(isToggledHiddenByDefault: true)->placeholder('—'),
                TextColumn::make('attribution_id')->label('Attribution')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                TernaryFilter::make('is_bot')->label('Bot'),
                SelectFilter::make('ambassador_profile_id')
                    ->label('Ambassador')
                    ->relationship('ambassador.user', 'email')
                    ->searchable(),
                Filter::make('created_at')
                    ->schema([
                        DatePicker::make('from'),
                        DatePicker::make('until'),
                    ])
                    ->query(function (Builder $q, array $data): Builder {
                        return $q
                            ->when($data['from'] ?? null, fn ($qq, $d) => $qq->whereDate('created_at', '>=', $d))
                            ->when($data['until'] ?? null, fn ($qq, $d) => $qq->whereDate('created_at', '<=', $d));
                    }),
                Filter::make('utm_source')
                    ->schema([TextInput::make('utm_source')])
                    ->query(fn (Builder $q, array $data) => $q->when($data['utm_source'] ?? null, fn ($qq, $s) => $qq->where('utm_source', 'like', "%{$s}%"))),
            ])
            ->recordActions([ViewAction::make()])
            ->toolbarActions([]);
    }

    /**
     * @return array<int, class-string<Widget>>
     */
    public static function getWidgets(): array
    {
        return [ReferralClickStats::class];
    }

    /**
     * @return array<string, PageRegistration>
     */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReferralClicks::route('/'),
            'view' => Pages\ViewReferralClick::route('/{record}'),
        ];
    }
}
