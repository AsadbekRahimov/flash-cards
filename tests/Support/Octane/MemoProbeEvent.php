<?php

declare(strict_types=1);

namespace Tests\Support\Octane;

/** Carries what MemoProbeListener saw: which memo objects the event dispatcher's container handed it. */
final class MemoProbeEvent
{
    public ?int $orgMemoId = null;

    public ?int $panelMemoId = null;

    public bool $orgMemoHasProbe = false;
}
