<?php

declare(strict_types=1);

namespace App\Policies\Org;

use App\Models\Learner;
use App\Models\Organization;
use App\Models\OrganizationInvite;
use App\Models\OrganizationMember;
use App\Models\OrganizationUser;
use App\Models\TelegramGroup;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Gate;

/**
 * Second line of tenant isolation in the `/org` cabinet (docs/13 §6.3, §10): while the `org`
 * panel is being served, Laravel's Gate resolves the policy of every cabinet model to the
 * tenant-aware policy of this namespace instead of the platform policy of `App\Policies`.
 *
 * Why here and not inside the platform policies: the same Eloquent models serve `/admin`
 * (superadmin: everything; Phase-1 teacher: own groups) and `/org` (owner / teacher of ONE
 * organization). Branching on "which panel" inside every method of every platform policy would
 * touch `/admin` code paths on each new cabinet model; one explicit map keeps `/admin` untouched
 * and makes the rule auditable in a single place. Outside the `org` panel (admin panel, API,
 * bot, console) the map is inert and the stock resolution applies.
 *
 * A cabinet model that is NOT in the map falls back to its platform policy, which grants nothing
 * to a user without a spatie `admin` / `teacher` role of guard `web` — in the `org` panel the
 * default guard is `org`, so even a superadmin opening a cabinet gets no implicit rights
 * (`OrgPanelAccessTest` pins this).
 */
final class OrgPolicyResolver
{
    public const PANEL_ID = 'org';

    /** @var array<class-string, class-string> */
    public const MAP = [
        Organization::class => OrgOrganizationPolicy::class,
        TelegramGroup::class => OrgTelegramGroupPolicy::class,
        OrganizationMember::class => OrgOrganizationMemberPolicy::class,
        OrganizationInvite::class => OrgOrganizationInvitePolicy::class,
        OrganizationUser::class => OrgOrganizationUserPolicy::class,
        Learner::class => OrgLearnerPolicy::class,
    ];

    public static function register(): void
    {
        Gate::guessPolicyNamesUsing(fn (string $modelClass): array => self::candidates($modelClass));
    }

    /**
     * Policy classes to try, in order. Mirrors Laravel's default guess (`App\Policies\<Model>Policy`)
     * and puts the tenant-aware policy first while the `org` panel is current.
     *
     * @return list<string>
     */
    public static function candidates(string $modelClass): array
    {
        $default = ['App\\Policies\\'.class_basename($modelClass).'Policy'];

        if (! self::inOrgPanel()) {
            return $default;
        }

        $orgPolicy = self::MAP[$modelClass] ?? null;

        return $orgPolicy === null ? $default : [$orgPolicy, ...$default];
    }

    public static function inOrgPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === self::PANEL_ID;
    }
}
