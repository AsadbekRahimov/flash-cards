<?php

declare(strict_types=1);

namespace App\Filament\Org\Pages;

use App\Domain\Localization\Services\LocaleResolver;
use App\Domain\Organizations\Services\OrganizationSettingsService;
use App\Models\Organization;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Forms;
use Filament\Pages\Tenancy\EditTenantProfile;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Settings of the organization (docs/13 §6.3): name, contact, timezone, language — owner only
 * (`OrgOrganizationPolicy::update`, checked by the page's `canView`). Saved through
 * OrganizationSettingsService (audit `organization.settings_updated`).
 */
class OrgSettings extends EditTenantProfile
{
    public static function getLabel(): string
    {
        return __('org.nav.settings');
    }

    public static function canView(Model $tenant): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && $tenant instanceof Organization && $user->can('update', $tenant);
    }

    public function form(Schema $schema): Schema
    {
        $locales = app(LocaleResolver::class)->supported();

        return $schema->components([
            Forms\Components\TextInput::make('name')->label(__('org.settings.fields.name'))->required()->maxLength(150),
            Forms\Components\TextInput::make('contact.email')->label(__('org.settings.fields.contact_email'))->email()->maxLength(120),
            Forms\Components\TextInput::make('contact.phone')->label(__('org.settings.fields.contact_phone'))->tel()->maxLength(120),
            Forms\Components\TextInput::make('contact.telegram')->label(__('org.settings.fields.contact_telegram'))->maxLength(120),
            Forms\Components\Select::make('timezone')
                ->label(__('org.settings.fields.timezone'))
                ->options(array_combine(timezone_identifiers_list(), timezone_identifiers_list()))
                ->searchable()
                ->required(),
            Forms\Components\Select::make('locale')
                ->label(__('org.settings.fields.locale'))
                ->options(array_combine($locales, array_map(strtoupper(...), $locales)))
                ->required(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Organization $organization */
        $organization = $this->tenant;
        $data['contact'] = OrganizationSettingsService::contactOf($organization);

        return $data;
    }

    /** @param array<string, mixed> $data */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var Organization $record */
        /** @var User $user */
        $user = Filament::auth()->user();

        /** @var array{name?: string, timezone?: string, locale?: string, contact?: array<string, string|null>} $data */
        return app(OrganizationSettingsService::class)->update($record, $data, $user, request()->ip());
    }
}
