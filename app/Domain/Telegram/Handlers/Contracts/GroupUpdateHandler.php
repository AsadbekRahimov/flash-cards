<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers\Contracts;

use App\Models\TelegramGroup;

interface GroupUpdateHandler extends UpdateHandler
{
    /** @param array<string, mixed> $update */
    public function handle(array $update, ?TelegramGroup $group = null): void;
}
