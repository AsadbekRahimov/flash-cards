<?php

declare(strict_types=1);

namespace App\Domain\Organizations\Services;

use Closure;

/**
 * Memory of the `/org` access checks for ONE request or ONE queued job (docs/20 §4 "Что показал замер", F4;
 * docs/13 §6.3).
 *
 * {@see OrgTenantScope} keeps here the role of a user in an organization and the ids of the groups that user may
 * see there. The policies of the cabinet ask for them on every row of a list and every action on it — before this
 * memory the members list ran 14 role lookups per row — so they are read once and remembered until the request
 * ends. Values are keyed by user id and organization id (`OrgTenantScope` builds the keys): a request serving one
 * user never answers for another user or another center.
 *
 * Lifetime — a `scoped` binding of the container (AppServiceProvider), i.e. the framework's own "per request"
 * contract:
 *  - PHP-FPM: a process serves one request, the container dies with it;
 *  - queue worker: `Worker::daemon()` runs the reset callback of `QueueServiceProvider::registerWorker()`
 *    before every job, and it calls `forgetScopedInstances()` (Laravel 13);
 *  - Octane: forgets scoped instances between requests;
 *  - a long-lived process without Octane (the test suite handles many requests in one container): the
 *    `RequestHandled` listener of AppServiceProvider flushes it after every handled request.
 *
 * Whatever changes the inputs inside the request drops the memory at once: a staff row written or deleted
 * (OrganizationUserObserver), a group linked / unlinked / deleted (TelegramGroupObserver), `teacher_groups`
 * rewritten (OrganizationStaffService). Query-builder writes (`OrganizationUser::query()->update()`) bypass the
 * observers: a caller that does them calls {@see flush()} itself. Decisions taken under a row lock
 * (OrganizationStaffService::invite) read the table, never this memory.
 *
 * Never inject it into a singleton: a singleton would keep the instance of the request it was created in
 * (the services that hold it — OrgTenantScope, the observers, the staff service — are all transient).
 */
final class OrgRequestMemo
{
    /** @var array<string, mixed> */
    private array $values = [];

    /**
     * The remembered value of $key, loading it with $load the first time.
     *
     * @template T
     *
     * @param  Closure(): T  $load
     * @return T
     */
    public function remember(string $key, Closure $load): mixed
    {
        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = $load();
        }

        /** @var T $value */
        $value = $this->values[$key];

        return $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    /** Forgets everything: the next read of any value goes to the database. */
    public function flush(): void
    {
        $this->values = [];
    }
}
