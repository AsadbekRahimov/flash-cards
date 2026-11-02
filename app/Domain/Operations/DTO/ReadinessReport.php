<?php

declare(strict_types=1);

namespace App\Domain\Operations\DTO;

final readonly class ReadinessReport
{
    public const OK = 'ok';

    public const FAIL = 'fail';

    /** A dependency the application works without (the pure cache, docs/19 §4.1): visible, but no reason for a 503. */
    public const DEGRADED = 'degraded';

    /**
     * @param  array<string, string>  $checks  check name => "ok"|"degraded"|"fail"
     */
    public function __construct(public array $checks) {}

    /** No check failed; a degraded check does not count: the application serves. */
    public function ok(): bool
    {
        foreach ($this->checks as $status) {
            if ($status === self::FAIL) {
                return false;
            }
        }

        return true;
    }

    public function degraded(): bool
    {
        return in_array(self::DEGRADED, $this->checks, true);
    }

    /** fail wins over degraded, degraded over ok. */
    public function status(): string
    {
        if (! $this->ok()) {
            return self::FAIL;
        }

        return $this->degraded() ? self::DEGRADED : self::OK;
    }
}
