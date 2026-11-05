<?php

declare(strict_types=1);

namespace Tests\Support\Octane;

use App\Domain\Administration\Services\PanelRequestMemo;
use App\Domain\Organizations\Services\OrgRequestMemo;

/**
 * Stands in for an Eloquent observer: a class listener the event dispatcher instantiates through ITS container
 * (`Dispatcher::createClassCallable()`), which under Octane is the base application, not the sandbox of the request.
 */
final class MemoProbeListener
{
    public function __construct(private readonly OrgRequestMemo $org, private readonly PanelRequestMemo $panel) {}

    public function handle(MemoProbeEvent $event): void
    {
        $event->orgMemoId = spl_object_id($this->org);
        $event->panelMemoId = spl_object_id($this->panel);
        $event->orgMemoHasProbe = $this->org->has('probe');
    }
}
