<?php

declare(strict_types=1);

namespace App\Domain\Operations\DTO;

final readonly class ReadinessReport
{
    public const OK = 'ok';

    public const FAIL = 'fail';

    /**
     * @param  array<string, string>  $checks  check name => "ok"|"fail"
     */
    public function __construct(public array $checks) {}

    public function ok(): bool
    {
        foreach ($this->checks as $status) {
            if ($status !== self::OK) {
                return false;
            }
        }

        return true;
    }
}
