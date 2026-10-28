<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Billing\Enums\Plan;
use App\Domain\Billing\Services\PromoCodeService;
use App\Filament\Resources\PromoCodeResource\Pages;
use App\Filament\Resources\Support\AdminLabels;
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

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.promo_codes.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.promo_codes.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.promo_codes.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('code')
                ->label(__('admin.promo_codes.fields.code'))
                ->required()
                ->default(fn (): string => app(PromoCodeService::class)->generateCode())
                ->helperText(__('admin.promo_codes.fields.code_help'))
                ->disabledOn('edit')
                ->rules([
                    fn (?PromoCode $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        $normalized = PromoCodeService::normalize((string) $value);

                        if (preg_match('/\A[A-Z0-9]{6,32}\z/', $normalized) !== 1) {
                            $fail(__('admin.promo_codes.fields.code_invalid'));

                            return;
                        }

                        $taken = PromoCode::query()
                            ->where('code', $normalized)
                            ->when($record !== null, fn ($query) => $query->whereKeyNot($record?->getKey()))
                            ->exists();

                        if ($taken) {
                            $fail(__('admin.promo_codes.fields.code_taken'));
                        }
                    },
                ]),
            Forms\Components\Select::make('plan')
                ->label(__('admin.promo_codes.fields.plan'))
                ->options(self::grantablePlans())
                ->default(Plan::Pro->value)
                ->required(),
            Forms\Components\TextInput::make('duration_days')
                ->label(__('admin.promo_codes.fields.duration_days'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->maxValue(3650)
                ->default(30)
                ->required(),
            Forms\Components\TextInput::make('max_redemptions')
                ->label(__('admin.promo_codes.fields.max_redemptions'))
                ->numeric()
                ->integer()
                ->minValue(1)
                ->helperText(__('admin.promo_codes.fields.max_redemptions_help')),
            Forms\Components\DateTimePicker::make('expires_at')
                ->label(__('admin.promo_codes.fields.expires_at'))
                ->timezone(config('learners.default_timezone'))
                ->helperText(__('admin.promo_codes.fields.expires_at_help', ['tz' => config('learners.default_timezone')])),
            Forms\Components\Toggle::make('is_active')->label(__('admin.promo_codes.fields.is_active'))->default(true),
            Forms\Components\Textarea::make('note')->label(__('admin.promo_codes.fields.note'))->rows(2)->maxLength(500)->columnSpanFull(),
            Forms\Components\TextInput::make('redemptions_count')
                ->label(__('admin.promo_codes.fields.redemptions_count'))
                ->disabled()
                ->dehydrated(false)
                ->visibleOn('edit'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label(__('admin.promo_codes.fields.code'))->searchable()->copyable()->fontFamily('mono')->sortable(),
                Tables\Columns\TextColumn::make('plan')
                    ->label(__('admin.promo_codes.fields.plan'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('common.plan', $state)),
                Tables\Columns\TextColumn::make('duration_days')->label(__('admin.promo_codes.columns.days'))->sortable(),
                Tables\Columns\TextColumn::make('redemptions_count')
                    ->label(__('admin.promo_codes.fields.redemptions_count'))
                    ->formatStateUsing(fn (PromoCode $record): string => $record->redemptions_count.' / '.($record->max_redemptions ?? '∞'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->label(__('admin.promo_codes.fields.expires_at'))->dateTime()->placeholder('—')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->label(__('admin.promo_codes.fields.is_active'))->boolean(),
                Tables\Columns\TextColumn::make('createdBy.name')->label(__('admin.promo_codes.columns.created_by'))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('note')->label(__('admin.promo_codes.fields.note'))->limit(40)->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.promo_codes.columns.created_at'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')->label(__('admin.promo_codes.fields.is_active')),
            ])
            ->emptyStateHeading(__('admin.promo_codes.empty'))
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
                $plans[$plan->value] = AdminLabels::value('common.plan', $plan->value);
            }
        }

        return $plans;
    }
}
