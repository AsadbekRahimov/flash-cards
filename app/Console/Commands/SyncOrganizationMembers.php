<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Organizations\Services\OrganizationMembershipService;
use App\Models\Organization;
use App\Models\TelegramGroup;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Creates `organization_members` rows (`joined_via = group`) for the active members of every
 * group of every active organization who have none (docs/13 §2.3): the demo seed, tests, and
 * the repair path of the best-effort hook in GroupMembershipService::join. Idempotent; seats
 * are taken FIFO by the order of groups and learner ids, waitlisted beyond the limit.
 */
final class SyncOrganizationMembers extends Command
{
    /** @var string */
    protected $signature = 'organizations:sync-members {--organization= : Only this organization (slug)}';

    /** @var string */
    protected $description = 'Create organization memberships from the current active group memberships of organization groups.';

    public function handle(OrganizationMembershipService $memberships): int
    {
        $now = CarbonImmutable::now();
        $slug = $this->option('organization');
        $query = Organization::query()->where('status', 'active')->orderBy('id');

        if (is_string($slug) && $slug !== '') {
            $query->where('slug', $slug);

            if (! $query->exists()) {
                $this->error("No active organization with slug [{$slug}].");

                return self::FAILURE;
            }
        }

        $total = 0;

        foreach ($query->get() as $organization) {
            $synced = 0;

            TelegramGroup::query()
                ->where('organization_id', $organization->id)
                ->orderBy('id')
                ->each(function (TelegramGroup $group) use ($memberships, $now, &$synced): void {
                    $synced += $memberships->syncGroup($group, $now);
                });

            $total += $synced;
            $this->line("{$organization->slug}: {$synced} membership(s) created");
        }

        $this->info("Organization memberships synced: {$total}");

        return self::SUCCESS;
    }
}
