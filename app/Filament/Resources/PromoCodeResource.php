<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\PromoCodeService;
use App\Filament\Resources\PromoCodeResource\Pages;
use App\Models\PromoCode;
use Closure;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Promo codes (docs/12 §5.5). Admin only (PromoCodePolicy). The code is stored normalized
 * (PromoCodeService::normalize), creation is audited by PromoCodeAdminService, the redemption
 * counter is read-only: only a redemption moves it.
 */
class PromoCodeResource extends Resource
{
    protected static ?string $model = PromoCode::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-ticket';

    protected static string|\UnitEnum|null $navigationGroup = 'Billing';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('code')
                ->required()
                ->default(fn (): string => app(PromoCodeService::class)->generateCode())
                ->helperText('6–32 Latin letters or digits; spaces and dashes are dropped. Cannot be changed after creation.')
                ->disabledOn('edit')
                ->rules([
                    fn (?PromoCode $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $normalized = PromoCodeService::normalize((string) $value);

                        if (preg_match('/\A[A-Z0-9]{6,32}\z/', $normalized) !== 1) {
                            $fail('The code must be 6–32 Latin letters or digits (spaces and dashes are ignored).');

                            return;
                        }

                        $taken = PromoCode::query()
                            ->where('code', $normalized)
                            ->when($record !== null, fn ($query) => $query->whereKeyNot($record?->getKey()))
                            ->exists();

                        if ($taken) {
                            $fail('This code already exists.');
                        }
                    },
                ]),
            Forms\Components\Select::make('plan')
                ->options(self::grantablePlans())
                ->default(Plan::Pro->value)
                ->required(),
            Forms\Components\TextInput::make('duration_days')
                ->label('Duration (days)')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(3650)
                ->default(30)
                ->required(),
            Forms\Components\TextInput::make('max_redemptions')
                ->label('Max redemptions')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->helperText('Empty = unlimited. One learner can redeem a code only once either way.'),
            Forms\Components\DateTimePicker::make('expires_at')
                ->label('Valid until')
                ->timezone(config('learners.default_timezone'))
                ->helperText('Empty = no expiry date. Time zone: '.config('learners.default_timezone').'.'),
            Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
            Forms\Components\Textarea::make('note')->rows(2)->maxLength(500)->columnSpanFull(),
            Forms\Components\TextInput::make('redemptions_count')
                ->label('Redeemed')
                ->disabled()
                ->dehydrated(false)
                ->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->copyable()->fontFamily('mono')->sortable(),
                Tables\Columns\TextColumn::make('plan')->badge(),
                Tables\Columns\TextColumn::make('duration_days')->label('Days')->sortable(),
                Tables\Columns\TextColumn::make('redemptions_count')
                    ->label('Redeemed')
                    ->formatStateUsing(fn (PromoCode $record): string => $record->redemptions_count.' / '.($record->max_redemptions ?? '∞'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime()->placeholder('—')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label('Active')->boolean(),
                Tables\Columns\TextColumn::make('createdBy.name')->label('Created by')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('note')->limit(40)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Actions\EditAction::make(),
                Actions\DeleteAction::make(),
            ])
            ->defaultSort('id', 'desc');
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPromoCodes::route('/'),
            'create' => Pages\CreatePromoCode::route('/create'),
            'edit' => Pages\EditPromoCode::route('/{record}/edit'),
        ];
    }

    /** @return array<string, string> plan key => label, Free excluded (it is not a subscription) */
    private static function grantablePlans(): array
    {
        $plans = [];
        foreach (Plan::cases() as $plan) {
            if ($plan->rank() > Plan::Free->rank()) {
                $plans[$plan->value] = ucfirst($plan->value);
            }
        }

        return $plans;
    }
}
