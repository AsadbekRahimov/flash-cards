<?php

declare(strict_types=1);

namespace App\Domain\Telegram\Handlers\Contracts;

interface UpdateHandler
{
    /** @param array<string, mixed> $update */
    public function matches(array $update): bool;

    /** @param array<string, mixed> $update */
    public function handle(array $update): void;
}
