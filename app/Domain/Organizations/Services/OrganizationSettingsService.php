<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use App\Domain\Localization\Services\LocaleResolver;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What the OWNER may change about their organization from the cabinet (docs/13 §6.3 Settings):
 * name, contact, timezone, language. `slug`, `type` and `status` stay with the superadmin
 * (`OrganizationAdminService`). Audited as `organization.settings_updated` with the changed keys
 * (old / new for `name`, so a rename is traceable in support).
 */
final class OrganizationSettingsService
{
    public const AUDIT_UPDATED = 'organization.settings_updated';

    public const CONTACT_KEYS = ['email', 'phone', 'telegram'];

    public function __construct(private readonly LocaleResolver $locales) {}

    /**
     * @param  array{name?: string, timezone?: string, locale?: string, contact?: array<string, string|null>}  $data
     */
    public function update(Organization $organization, array $data, User $by, ?string $ip = null): Organization
    {
        return DB::transaction(function () use ($organization, $data, $by, $ip): Organization {
            /** @var Organization $current */
            $current = Organization::query()->whereKey($organization->id)->lockForUpdate()->firstOrFail();

            $changes = [];

            if (isset($data['name'])) {
                $name = mb_substr(trim($data['name']), 0, 150);
                if ($name !== '' && $name !== $current->name) {
                    $changes['name'] = ['from' => $current->name, 'to' => $name];
                    $current->name = $name;
                }
            }

            if (isset($data['timezone']) && in_array($data['timezone'], timezone_identifiers_list(), true) && $data['timezone'] !== $current->timezone) {
                $changes['timezone'] = ['from' => $current->timezone, 'to' => $data['timezone']];
                $current->timezone = $data['timezone'];
            }

            if (isset($data['locale']) && $this->locales->isSupported($data['locale']) && $data['locale'] !== $current->locale) {
                $changes['locale'] = ['from' => $current->locale, 'to' => $data['locale']];
                $current->locale = $data['locale'];
            }

            if (array_key_exists('contact', $data)) {
                $contact = $this->normalizeContact($data['contact']);
                $settings = $current->settings;
                if (($settings['contact'] ?? []) !== $contact) {
                    $changes['contact'] = true;
                    $settings['contact'] = $contact;
                    $current->settings = $settings;
                }
            }

            if ($changes === []) {
                return $current;
            }

            $current->save();

            AuditLog::create([
                'user_id' => $by->id,
                'action' => self::AUDIT_UPDATED,
                'entity_type' => Organization::class,
                'entity_id' => $current->id,
                'ip' => $ip,
                'meta' => ['changes' => $changes],
            ]);

            return $current;
        });
    }

    /**
     * @return array{email: string|null, phone: string|null, telegram: string|null}
     */
    public static function contactOf(Organization $organization): array
    {
        /** @var array<string, mixed> $contact */
        $contact = (array) ($organization->settings['contact'] ?? []);

        return [
            'email' => is_string($contact['email'] ?? null) ? $contact['email'] : null,
            'phone' => is_string($contact['phone'] ?? null) ? $contact['phone'] : null,
            'telegram' => is_string($contact['telegram'] ?? null) ? $contact['telegram'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $contact
     * @return array<string, string>
     */
    private function normalizeContact(array $contact): array
    {
        $clean = [];

        foreach (self::CONTACT_KEYS as $key) {
            $value = $contact[$key] ?? null;
            $value = is_string($value) ? trim(strip_tags($value)) : '';

            if ($value !== '') {
                $clean[$key] = mb_substr($value, 0, 120);
            }
        }

        return $clean;
    }
}
