<?php

declare(strict_types=1);

namespace App\Octane\Listeners;

use App\Domain\Administration\Services\PanelRequestMemo;
use App\Domain\Organizations\Services\OrgRequestMemo;
use Laravel\Octane\Events\RequestReceived;

/**
 * One instance of each per-request memory for the whole request, whichever container resolves it (docs/21 §2.2 №12).
 *
 * Octane serves a request from a sandbox (a clone of the booted application): policies, services and middleware get
 * `OrgRequestMemo` / `PanelRequestMemo` through `app()`, i.e. from the sandbox. The event dispatcher, however, was built
 * when the worker booted and keeps the BASE application as its container, so the class listeners and the Eloquent
 * observers it instantiates (`Dispatcher::createClassCallable()` -> `$this->container->make()`) get their memo from the
 * base application - a second, different object. The `flush()` an observer does when a staff row or a group changes
 * would then never reach the memory the policies of the same request read (docs/20 F4 "reset in the same transaction").
 *
 * Resolving the memo in the base application and handing the same object to the sandbox makes both sides see one
 * memory. Nothing outlives the request: the sandbox is flushed when the request ends, and Octane's own
 * `FlushTemporaryContainerInstances` (OperationTerminated) calls `forgetScopedInstances()` on the base application,
 * which drops the scoped memo there (`WorkerIsolationTest` proves both). php-fpm and the queue workers never run this.
 */
final class ShareRequestMemosWithSandbox
{
    /** @var list<class-string> */
    private const MEMOS = [OrgRequestMemo::class, PanelRequestMemo::class];

    public function handle(RequestReceived $event): void
    {
        foreach (self::MEMOS as $memo) {
            // Insurance: when the previous request died in terminate() its FlushTemporaryContainerInstances never ran.
            $event->app->forgetInstance($memo);
            $event->sandbox->instance($memo, $event->app->make($memo));
        }
    }
}
