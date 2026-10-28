<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Domain\Administration\Services\PanelDataScope;
use App\Filament\Resources\Support\AdminLabels;
use App\Filament\Resources\TelegramGroupResource\Pages;
use App\Models\TelegramGroup;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TelegramGroupResource extends Resource
{
    protected static ?string $model = TelegramGroup::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 20;

    public static function getNavigationGroup(): ?string
    {
        return __('admin.nav.administration');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.telegram_groups.nav');
    }

    public static function getModelLabel(): string
    {
        return __('admin.telegram_groups.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.telegram_groups.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Forms\Components\TextInput::make('chat_id')->label(__('admin.telegram_groups.fields.chat_id'))->disabled(),
            Forms\Components\TextInput::make('title')->label(__('admin.telegram_groups.fields.title'))->required()->maxLength(255),
            Forms\Components\Select::make('status')
                ->label(__('admin.telegram_groups.fields.status'))
                ->options(AdminLabels::options('telegram_groups.status', ['pending', 'active', 'disabled']))
                ->required(),
            Forms\Components\Select::make('course_id')
                ->label(__('admin.telegram_groups.fields.course'))
                // Archived courses are not offered — except the one the group is already bound to (so its label still shows).
                ->relationship('course', 'slug', fn (Builder $query, ?Model $record): Builder => $query->where(
                    fn (Builder $q): Builder => $q->where('courses.status', '!=', 'archived')->orWhere('courses.id', $record?->getAttribute('course_id')),
                ))
                ->placeholder(__('admin.telegram_groups.fields.course_placeholder'))
                ->helperText(__('admin.telegram_groups.fields.course_help'))
                ->preload(),
            // Stored as meta['locale']; see EditTelegramGroup for the mapping.
            Forms\Components\Select::make('locale')
                ->label(__('admin.telegram_groups.fields.locale'))
                ->helperText(__('admin.telegram_groups.fields.locale_help'))
                ->options([
                    'ru' => 'Русский',
                    'uz' => "O'zbekcha",
                ])
                ->placeholder(__('admin.telegram_groups.fields.locale_placeholder')),
            Forms\Components\KeyValue::make('meta')->label(__('admin.telegram_groups.fields.meta'))->columnSpanFull(),
            Forms\Components\Select::make('teachers')
                ->label(__('admin.telegram_groups.fields.teachers'))
                ->relationship('teachers', 'name')
                ->multiple()
                ->preload()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('chat_id')->label(__('admin.telegram_groups.fields.chat_id'))->copyable()->searchable(),
                Tables\Columns\TextColumn::make('title')->label(__('admin.telegram_groups.fields.title'))->searchable()->limit(40),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('admin.telegram_groups.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => AdminLabels::value('telegram_groups.status', $state))
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'pending' => 'warning',
                        'disabled' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('members_count')
                    ->counts(['members' => fn (Builder $q): Builder => $q->where('status', 'active')])
                    ->label(__('admin.telegram_groups.fields.learners')),
                Tables\Columns\TextColumn::make('created_at')->label(__('admin.telegram_groups.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('admin.telegram_groups.fields.status'))
                    ->options(AdminLabels::options('telegram_groups.status', ['pending', 'active', 'disabled'])),
            ])
            ->emptyStateHeading(__('admin.telegram_groups.empty'))
            ->actions([
                Actions\Action::make('activate')
                    ->label(__('admin.telegram_groups.actions.activate'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (TelegramGroup $record): bool => app(PanelDataScope::class)->isAdmin() && $record->status !== 'active')
                    ->requiresConfirmation()
                    ->action(fn (TelegramGroup $record) => $record->update(['status' => 'active'])),
                Actions\Action::make('deactivate')
                    ->label(__('admin.telegram_groups.actions.deactivate'))
                    ->icon('heroicon-o-x-circle')
                    ->color('danger')
                    ->visible(fn (TelegramGroup $record): bool => app(PanelDataScope::class)->isAdmin() && $record->status === 'active')
                    ->requiresConfirmation()
                    ->action(fn (TelegramGroup $record) => $record->update(['status' => 'disabled'])),
                Actions\EditAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /** @return Builder<TelegramGroup> */
    public static function getEloquentQuery(): Builder
    {
        return app(PanelDataScope::class)->groups();
    }

    /** @return array<int, class-string> */
    public static function getRelations(): array
    {
        return [];
    }

    /** @return array<string, PageRegistration> */
    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramGroups::route('/'),
            'edit' => Pages\EditTelegramGroup::route('/{record}/edit'),
        ];
    }
}
